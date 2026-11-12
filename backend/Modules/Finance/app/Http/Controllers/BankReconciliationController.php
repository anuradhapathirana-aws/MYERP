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
use Modules\Finance\DTOs\BankReconciliationData;
use Modules\Finance\Enums\BankReconciliationStatus;
use Modules\Finance\Http\Requests\BankEntryRequest;
use Modules\Finance\Http\Requests\BankReconciliationRequest;
use Modules\Finance\Http\Resources\BankReconciliationResource;
use Modules\Finance\Models\BankReconciliation;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\BankReconciliationService;
use Modules\Finance\Services\FinanceControlService;

/**
 * Finance > Banking > Bank Reconciliation.
 *
 * Matching a statement (create / edit a draft), committing it (post) and
 * undoing the latest one (void) are three levels of trust. Posting a
 * bank-only entry (bank charges or interest, to their mapped accounts only)
 * writes to the ledger, so it needs the same right as posting the
 * reconciliation.
 */
class BankReconciliationController extends Controller
{
    public function __construct(
        private readonly BankReconciliationService $service,
        private readonly FinanceControlService     $controls,
        private readonly CompanyAccessService      $companyAccess,
    ) {
        $this->middleware('permission:view_bank_reconciliations')
            ->only(['index', 'show', 'history', 'nextNo', 'options', 'workspace', 'search']);
        $this->middleware('permission:create_bank_reconciliations')->only(['store']);
        $this->middleware('permission:edit_bank_reconciliations')->only(['update']);
        $this->middleware('permission:delete_bank_reconciliations')->only(['destroy']);
        $this->middleware('permission:post_bank_reconciliations')->only(['post', 'addEntry']);
        $this->middleware('permission:void_bank_reconciliations')->only(['void']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'search', 'status', 'company_id', 'bank_account_id', 'date_from', 'date_to', 'sort', 'direction',
        ]);

        $paginator = $this->service->paginate($filters, (int) $request->query('per_page', 25));

        return response()->json([
            'data' => BankReconciliationResource::collection($paginator->items())->toArray($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, BankReconciliation $bankReconciliation): JsonResponse
    {
        return response()->json([
            'data' => (new BankReconciliationResource($this->service->find($bankReconciliation)))->toArray($request),
        ]);
    }

    /** Companies and bank accounts this user may reconcile, statuses, and the accounts a bank entry may use. */
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
                'statuses'      => BankReconciliationStatus::options(),
                'lock_date'     => $this->controls->lockDate()?->toDateString(),
                'maker_checker' => $this->controls->makerCheckerEnabled(),
                ...$this->service->entryOptions(),
            ],
        ]);
    }

    public function nextNo(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['recon_no' => $this->service->previewNextNo($request->query('statement_date'))],
        ]);
    }

    /**
     * Opening balance, book balance and every uncleared bank line from the
     * start date up to the statement date. date_from counts only on an
     * account's first reconciliation, where it is the start date.
     */
    public function workspace(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bank_account_id'   => ['required', 'integer', 'min:1'],
            'statement_date'    => ['nullable', 'date_format:Y-m-d'],
            'date_from'         => ['nullable', 'date_format:Y-m-d'],
            'reconciliation_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json([
            'data' => $this->service->workspace(
                (int) $validated['bank_account_id'],
                $validated['statement_date'] ?? CarbonImmutable::today()->toDateString(),
                isset($validated['reconciliation_id']) ? (int) $validated['reconciliation_id'] : null,
                $validated['date_from'] ?? null,
            ),
        ]);
    }

    /**
     * Add Transaction: uncleared entries of any date, by date, cheque no,
     * amount or text -- or, by "returned", a cheque an earlier reconciliation
     * realized that bounced afterwards. Also accepted as POST, so a long list
     * of lines already on screen never makes the URL too long.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bank_account_id' => ['required', 'integer', 'min:1'],
            'by'              => ['required', 'in:date,cheque,amount,description,returned'],
            'term'            => ['required', 'string', 'max:100'],
            'except'          => ['nullable', 'array', 'max:5000'],
            'except.*'        => ['integer'],
        ]);

        return response()->json([
            'data' => $this->service->search(
                (int) $validated['bank_account_id'],
                $validated['by'],
                $validated['term'],
                array_values(array_map('intval', $validated['except'] ?? [])),
            ),
        ]);
    }

    /** Post bank charges or interest from the statement; returns its bank line, ready to realize. */
    public function addEntry(BankEntryRequest $request): JsonResponse
    {
        $v = $request->validated();

        return response()->json([
            'data' => $this->service->addBankEntry([
                'bank_account_id' => (int) $v['bank_account_id'],
                'entry_date'      => $v['entry_date'],
                'type'            => $v['type'],
                'amount'          => number_format((float) $v['amount'], 2, '.', ''),
                'description'     => $v['description'],
                'reference'       => $v['reference'] ?? null,
                'request_id'      => $v['request_id'] ?? null,
            ]),
        ], 201);
    }

    public function store(BankReconciliationRequest $request): JsonResponse
    {
        $recon = $this->service->create(BankReconciliationData::fromRequest($request));

        return response()->json(['data' => (new BankReconciliationResource($recon))->toArray($request)], 201);
    }

    public function update(BankReconciliationRequest $request, BankReconciliation $bankReconciliation): JsonResponse
    {
        $recon = $this->service->update($bankReconciliation, BankReconciliationData::fromRequest($request));

        return response()->json(['data' => (new BankReconciliationResource($recon))->toArray($request)]);
    }

    public function destroy(BankReconciliation $bankReconciliation): JsonResponse
    {
        $this->service->delete($bankReconciliation);

        return response()->json(null, 204);
    }

    public function post(Request $request, BankReconciliation $bankReconciliation): JsonResponse
    {
        $recon = $this->service->post($bankReconciliation);

        return response()->json(['data' => (new BankReconciliationResource($recon))->toArray($request)]);
    }

    public function void(Request $request, BankReconciliation $bankReconciliation): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $recon = $this->service->void($bankReconciliation, trim(strip_tags($validated['reason'])));

        return response()->json(['data' => (new BankReconciliationResource($recon))->toArray($request)]);
    }

    public function history(BankReconciliation $bankReconciliation): JsonResponse
    {
        return response()->json([
            'data' => $this->service->history($bankReconciliation)->map(fn ($log): array => [
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
