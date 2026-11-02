<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Finance\DTOs\BankDepositData;
use Modules\Finance\Enums\BankDepositStatus;
use Modules\Finance\Http\Requests\BankDepositRequest;
use Modules\Finance\Http\Resources\BankDepositResource;
use Modules\Finance\Models\BankDeposit;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\BankDepositService;
use Modules\Finance\Services\FinanceControlService;

/**
 * Finance > Banking > Bank Deposits.
 *
 * Same permission split as Bill Payments: entering a deposit, banking it
 * (confirm) and reversing it (void) are three levels of trust.
 */
class BankDepositController extends Controller
{
    public function __construct(
        private readonly BankDepositService    $service,
        private readonly FinanceControlService $controls,
        private readonly CompanyAccessService  $companyAccess,
    ) {
        $this->middleware('permission:view_bank_deposits')
            ->only(['index', 'show', 'history', 'nextNo', 'options', 'inHand']);
        $this->middleware('permission:create_bank_deposits')->only(['store']);
        $this->middleware('permission:edit_bank_deposits')->only(['update']);
        $this->middleware('permission:delete_bank_deposits')->only(['destroy']);
        $this->middleware('permission:confirm_bank_deposits')->only(['confirm']);
        $this->middleware('permission:void_bank_deposits')->only(['void']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'search', 'status', 'company_id', 'bank_account_id', 'date_from', 'date_to', 'sort', 'direction',
        ]);

        $paginator = $this->service->paginate($filters, (int) $request->query('per_page', 25));

        return response()->json([
            'data' => BankDepositResource::collection($paginator->items())->toArray($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, BankDeposit $bankDeposit): JsonResponse
    {
        return response()->json([
            'data' => (new BankDepositResource($this->service->find($bankDeposit)))->toArray($request),
        ]);
    }

    /**
     * Dropdown values: companies this user may work with, the Bank accounts a
     * deposit may go into, statuses, lock date and maker-checker. What is
     * waiting to be banked comes from inHand(), per company.
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

        $banks = LedgerAccount::query()
            ->bankAccounts()
            ->with(['bank:id,bank_name', 'bankBranch:id,branch_name'])
            ->when($allowed !== null, fn ($q) => $q->whereIn('company_id', $allowed))
            ->orderBy('code')
            ->get(['id', 'code', 'ledger_account_name', 'company_id', 'bank_id', 'bank_branch_id', 'bank_account_no'])
            ->map(fn (LedgerAccount $a): array => [
                'id'              => $a->id,
                'code'            => $a->code,
                'name'            => $a->ledger_account_name,
                'company_id'      => $a->company_id,
                'bank_name'       => $a->bank?->bank_name,
                'branch_name'     => $a->bankBranch?->branch_name,
                'bank_account_no' => $a->bank_account_no,
            ]);

        return response()->json([
            'data' => [
                'companies'     => $companies,
                'bank_accounts' => $banks,
                'statuses'      => BankDepositStatus::options(),
                'lock_date'     => $this->controls->lockDate()?->toDateString(),
                'maker_checker' => $this->controls->makerCheckerEnabled(),
            ],
        ]);
    }

    public function nextNo(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['deposit_no' => $this->service->previewNextNo($request->query('deposit_date'))],
        ]);
    }

    /** A company's cash books with what each holds, and its cheques in hand. */
    public function inHand(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id'   => ['required', 'integer', 'min:1'],
            'deposit_date' => ['nullable', 'date_format:Y-m-d'],
            'deposit_id'   => ['nullable', 'integer', 'min:1'],
        ]);

        $depositId = isset($validated['deposit_id']) ? (int) $validated['deposit_id'] : null;

        // A deposit's own cheques are offered back only to someone who may see it.
        if ($depositId !== null) {
            $this->service->assertAccessible(BankDeposit::findOrFail($depositId));
        }

        return response()->json([
            'data' => $this->service->inHand(
                (int) $validated['company_id'],
                $validated['deposit_date'] ?? CarbonImmutable::today()->toDateString(),
                $depositId,
            ),
        ]);
    }

    public function store(BankDepositRequest $request): JsonResponse
    {
        $deposit = $this->service->create(BankDepositData::fromRequest($request));

        return response()->json(['data' => (new BankDepositResource($deposit))->toArray($request)], 201);
    }

    public function update(BankDepositRequest $request, BankDeposit $bankDeposit): JsonResponse
    {
        $deposit = $this->service->update($bankDeposit, BankDepositData::fromRequest($request));

        return response()->json(['data' => (new BankDepositResource($deposit))->toArray($request)]);
    }

    public function destroy(BankDeposit $bankDeposit): JsonResponse
    {
        $this->service->delete($bankDeposit);

        return response()->json(null, 204);
    }

    public function confirm(Request $request, BankDeposit $bankDeposit): JsonResponse
    {
        $deposit = $this->service->confirm($bankDeposit);

        return response()->json(['data' => (new BankDepositResource($deposit))->toArray($request)]);
    }

    public function void(Request $request, BankDeposit $bankDeposit): JsonResponse
    {
        $validated = $request->validate([
            'reason'        => ['required', 'string', 'min:3', 'max:500'],
            'reversal_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $deposit = $this->service->void(
            $bankDeposit,
            trim(strip_tags($validated['reason'])),
            $validated['reversal_date'],
        );

        return response()->json(['data' => (new BankDepositResource($deposit))->toArray($request)]);
    }

    public function history(BankDeposit $bankDeposit): JsonResponse
    {
        return response()->json([
            'data' => $this->service->history($bankDeposit)->map(fn ($log): array => [
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
