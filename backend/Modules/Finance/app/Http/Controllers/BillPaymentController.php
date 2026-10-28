<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use App\Models\Company;
use App\Models\PaymentMode;
use App\Models\User;
use App\Services\CompanyAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Finance\DTOs\BillPaymentData;
use Modules\Finance\Enums\BillPaymentStatus;
use Modules\Finance\Http\Requests\BillPaymentRequest;
use Modules\Finance\Http\Resources\BillPaymentResource;
use Modules\Finance\Models\BillPayment;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\BillPaymentService;
use Modules\Finance\Services\FinanceControlService;
use Modules\Finance\Support\PaymentModeRules;

/**
 * Finance > Payables > Bill Payments.
 *
 * Same permission split as Bills: entering a payment, paying it out
 * (confirm) and reversing it (void) are three levels of trust.
 */
class BillPaymentController extends Controller
{
    public function __construct(
        private readonly BillPaymentService    $service,
        private readonly FinanceControlService $controls,
        private readonly CompanyAccessService  $companyAccess,
    ) {
        $this->middleware('permission:view_bill_payments')
            ->only(['index', 'show', 'history', 'nextNo', 'options', 'openBills']);
        $this->middleware('permission:create_bill_payments')->only(['store']);
        $this->middleware('permission:edit_bill_payments')->only(['update']);
        $this->middleware('permission:delete_bill_payments')->only(['destroy']);
        $this->middleware('permission:confirm_bill_payments')->only(['confirm']);
        $this->middleware('permission:void_bill_payments')->only(['void']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'search', 'status', 'payment_mode_id', 'supplier_id', 'company_id', 'pay_from_account_id',
            'date_from', 'date_to', 'sort', 'direction',
        ]);

        $paginator = $this->service->paginate($filters, (int) $request->query('per_page', 25));

        return response()->json([
            'data' => BillPaymentResource::collection($paginator->items())->toArray($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, BillPayment $billPayment): JsonResponse
    {
        return response()->json([
            'data' => (new BillPaymentResource($this->service->find($billPayment)))->toArray($request),
        ]);
    }

    /**
     * Dropdown values: the shared payment modes (with what each needs, so the
     * screen never re-derives the rules), the Cash Book and Bank accounts a
     * payment may come from, companies this user may work with, statuses,
     * lock date and maker-checker.
     */
    public function options(Request $request): JsonResponse
    {
        $user    = $request->user();
        $allowed = $this->companyAccess->allowedCompanyIds($user instanceof User ? $user : null);

        $companies = Company::query()
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->orderBy('company_name')
            ->get(['id', 'company_name'])
            ->map(fn (Company $c): array => ['id' => $c->id, 'name' => $c->company_name]);

        $accounts = LedgerAccount::query()
            ->payFrom()
            ->when($allowed !== null, fn ($q) => $q->where(
                fn ($w) => $w->whereNull('company_id')->orWhereIn('company_id', $allowed),
            ))
            ->orderBy('code')
            ->get(['id', 'code', 'ledger_account_name', 'company_id', 'cash_book_type'])
            ->map(fn (LedgerAccount $a): array => [
                'id'             => $a->id,
                'code'           => $a->code,
                'name'           => $a->ledger_account_name,
                'company_id'     => $a->company_id,
                'cash_book_type' => $a->cash_book_type?->value,
            ]);

        $modes = PaymentModeRules::usable()->get()
            ->map(fn (PaymentMode $m): array => [
                'id'              => $m->id,
                'name'            => $m->payment_mode_name,
                'type'            => $m->effectiveType()->value,
                'pays_from'       => PaymentModeRules::paysFrom($m)->value,
                'is_cheque'       => PaymentModeRules::isCheque($m),
                'needs_reference' => PaymentModeRules::needsReference($m),
            ])->values();

        return response()->json([
            'data' => [
                'pay_from_accounts' => $accounts,
                'companies'         => $companies,
                'payment_modes'     => $modes,
                'statuses'          => BillPaymentStatus::options(),
                'lock_date'         => $this->controls->lockDate()?->toDateString(),
                'maker_checker'     => $this->controls->makerCheckerEnabled(),
            ],
        ]);
    }

    public function nextNo(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['payment_no' => $this->service->previewNextNo($request->query('payment_date'))],
        ]);
    }

    /** The supplier's unpaid confirmed bills in one company, oldest due first. */
    public function openBills(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', 'min:1'],
            'company_id'  => ['required', 'integer', 'min:1'],
        ]);

        return response()->json([
            'data' => $this->service->openBills((int) $validated['supplier_id'], (int) $validated['company_id']),
        ]);
    }

    public function store(BillPaymentRequest $request): JsonResponse
    {
        $payment = $this->service->create(BillPaymentData::fromRequest($request));

        return response()->json(['data' => (new BillPaymentResource($payment))->toArray($request)], 201);
    }

    public function update(BillPaymentRequest $request, BillPayment $billPayment): JsonResponse
    {
        $payment = $this->service->update($billPayment, BillPaymentData::fromRequest($request));

        return response()->json(['data' => (new BillPaymentResource($payment))->toArray($request)]);
    }

    public function destroy(BillPayment $billPayment): JsonResponse
    {
        $this->service->delete($billPayment);

        return response()->json(null, 204);
    }

    public function confirm(Request $request, BillPayment $billPayment): JsonResponse
    {
        $payment = $this->service->confirm($billPayment);

        return response()->json(['data' => (new BillPaymentResource($payment))->toArray($request)]);
    }

    public function void(Request $request, BillPayment $billPayment): JsonResponse
    {
        $validated = $request->validate([
            'reason'        => ['required', 'string', 'min:3', 'max:500'],
            'reversal_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $payment = $this->service->void(
            $billPayment,
            trim(strip_tags($validated['reason'])),
            $validated['reversal_date'],
        );

        return response()->json(['data' => (new BillPaymentResource($payment))->toArray($request)]);
    }

    public function history(BillPayment $billPayment): JsonResponse
    {
        return response()->json([
            'data' => $this->service->history($billPayment)->map(fn ($log): array => [
                'id'         => $log->id,
                'event'      => $log->event,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'user_name'  => $log->user?->name,
                'created_at' => $log->created_at?->toISOString(),
            ])->values()->all(),
        ]);
    }
}
