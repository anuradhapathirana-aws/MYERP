<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use App\Contracts\LandedCostTargets;
use App\Models\Company;
use App\Models\Location;
use App\Models\SupplierMaster;
use App\Models\Tax;
use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\TaxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Finance\DTOs\BillData;
use Modules\Finance\Enums\BillStatus;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Http\Requests\BillRequest;
use Modules\Finance\Http\Resources\BillResource;
use Modules\Finance\Models\Bill;
use Modules\Finance\Models\ChargeType;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\BillService;
use Modules\Finance\Services\ChargeTypeService;
use Modules\Finance\Services\FinanceControlService;

/**
 * Finance > Payables > Bills.
 *
 * Permissions are per action, matching every other controller. confirm and
 * void are separate from create and edit: entering a bill, putting it in the
 * books and taking it out again are three levels of trust.
 */
class BillController extends Controller
{
    public function __construct(
        private readonly BillService           $service,
        private readonly FinanceControlService $controls,
        private readonly CompanyAccessService  $companyAccess,
        private readonly ChargeTypeService     $chargeTypes,
        private readonly TaxService            $taxes,
    ) {
        $this->middleware('permission:view_bills')
            ->only(['index', 'show', 'history', 'nextNo', 'options', 'supplierDefaults', 'grnOptions']);
        $this->middleware('permission:create_bills')->only(['store']);
        $this->middleware('permission:edit_bills')->only(['update']);
        $this->middleware('permission:delete_bills')->only(['destroy']);
        $this->middleware('permission:confirm_bills')->only(['confirm']);
        $this->middleware('permission:void_bills')->only(['void']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'search', 'status', 'payment_status', 'supplier_id', 'company_id',
            'date_from', 'date_to', 'overdue', 'sort', 'direction',
        ]);

        $paginator = $this->service->paginate($filters, (int) $request->query('per_page', 25));

        return response()->json([
            'data' => BillResource::collection($paginator->items())->toArray($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, Bill $bill): JsonResponse
    {
        return response()->json(['data' => $this->present($request, $this->service->find($bill))]);
    }

    /**
     * Dropdown values for the form and the list filters.
     *
     * Companies and locations come from here rather than the shared master
     * lookups because they must be filtered to the companies this user may
     * work with, and a location needs its company to be filtered on the form.
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

        $locations = Location::query()
            ->when($allowed !== null, fn ($q) => $q->whereIn('company_id', $allowed))
            ->orderBy('location_name')
            ->get(['id', 'location_name', 'company_id'])
            ->map(fn (Location $l): array => [
                'id' => $l->id, 'name' => $l->location_name, 'company_id' => (int) $l->company_id,
            ]);

        $accounts = LedgerAccount::query()
            ->billable()
            ->orderBy('code')
            ->get(['id', 'code', 'ledger_account_name', 'company_id'])
            ->map(fn (LedgerAccount $a): array => [
                'id'         => $a->id,
                'code'       => $a->code,
                'name'       => $a->ledger_account_name,
                'company_id' => $a->company_id,
            ]);

        $targets = app(LandedCostTargets::class);

        return response()->json([
            'data' => [
                'expense_accounts'  => $accounts,
                'charge_types'      => $this->chargeTypes->active()->map(fn (ChargeType $t): array => [
                    'id'                => $t->id,
                    'code'              => $t->code,
                    'name'              => $t->name,
                    'treatment'         => $t->treatment->value,
                    'treatment_label'   => $t->treatment->label(),
                    'links_to_grn'      => $t->treatment->linksToGrn(),
                    'ledger_account_id' => $t->ledger_account_id,
                ])->values(),
                // GRN linking needs Inventory; claimable Import VAT needs VAT on.
                'grn_link_available' => $targets->isAvailable(),
                'vat_active'        => $this->taxes->isActive(Tax::VAT),
                'companies'         => $companies,
                'locations'         => $locations,
                'statuses'          => BillStatus::options(),
                'payment_statuses'  => PaymentStatus::options(),
                'lock_date'         => $this->controls->lockDate()?->toDateString(),
                'maker_checker'     => $this->controls->makerCheckerEnabled(),
            ],
        ]);
    }

    /** Lock-free preview of the next bill number. Display only. */
    public function nextNo(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['bill_no' => $this->service->previewNextNo($request->query('transaction_date'))],
        ]);
    }

    public function supplierDefaults(Request $request, SupplierMaster $supplier): JsonResponse
    {
        return response()->json([
            'data' => $this->service->supplierDefaults($supplier, $request->query('transaction_date')),
        ]);
    }

    /** GET bills/grn-options?search= -- confirmed GRNs a landed-cost line can belong to. */
    public function grnOptions(Request $request): JsonResponse
    {
        $search = $request->query('search');

        return response()->json([
            'data' => app(LandedCostTargets::class)->searchGrns(is_string($search) ? mb_substr(trim($search), 0, 50) : null, 100),
        ]);
    }

    public function store(BillRequest $request): JsonResponse
    {
        $bill = $this->service->create(BillData::fromRequest($request));

        return response()->json(['data' => $this->present($request, $bill)], 201);
    }

    public function update(BillRequest $request, Bill $bill): JsonResponse
    {
        $bill = $this->service->update($bill, BillData::fromRequest($request));

        return response()->json(['data' => $this->present($request, $bill)]);
    }

    public function destroy(Bill $bill): JsonResponse
    {
        $this->service->delete($bill);

        return response()->json(null, 204);
    }

    public function confirm(Request $request, Bill $bill): JsonResponse
    {
        $bill = $this->service->confirm($bill);

        return response()->json(['data' => $this->present($request, $bill)]);
    }

    public function void(Request $request, Bill $bill): JsonResponse
    {
        $validated = $request->validate([
            'reason'        => ['required', 'string', 'min:3', 'max:500'],
            'reversal_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $bill = $this->service->void(
            $bill,
            trim(strip_tags($validated['reason'])),
            $validated['reversal_date'],
        );

        return response()->json(['data' => $this->present($request, $bill)]);
    }

    public function history(Bill $bill): JsonResponse
    {
        return response()->json([
            'data' => $this->service->history($bill)->map(fn ($log): array => [
                'id'         => $log->id,
                'event'      => $log->event,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'user_name'  => $log->user?->name,
                'created_at' => $log->created_at?->toISOString(),
            ])->values()->all(),
        ]);
    }

    /** One bill as the screen sees it, with the GRN numbers its lines link to. */
    private function present(Request $request, Bill $bill): array
    {
        return (new BillResource($bill))->withGrns($this->service->linkedGrns($bill))->toArray($request);
    }
}
