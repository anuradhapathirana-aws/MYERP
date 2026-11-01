<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Finance\DTOs\LedgerAccountData;
use Modules\Finance\Enums\CashBookType;
use Modules\Finance\Http\Requests\LedgerAccountRequest;
use Modules\Finance\Http\Resources\LedgerAccountResource;
use Modules\Finance\Models\Bill;
use Modules\Finance\Models\ControlAccount;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\FundBalanceService;
use Modules\Finance\Services\LedgerAccountService;

class LedgerAccountController extends Controller
{
    public function __construct(
        private readonly LedgerAccountService $service,
        private readonly FundBalanceService   $funds,
    ) {
        $this->middleware('permission:view_ledger_accounts')->only(['index', 'show', 'all', 'cashBookTypes', 'nextCode']);
        $this->middleware('permission:create_ledger_accounts')->only(['store']);
        $this->middleware('permission:edit_ledger_accounts')->only(['update']);
        $this->middleware('permission:delete_ledger_accounts')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $filters = request()->only([
            'search', 'control_account_id', 'account_category_id', 'account_type',
            'cash_book_type', 'company_id', 'is_active',
        ]);
        $paginator = $this->service->paginate(50, $filters);

        // Cash and bank rows show their posted balance, so an empty or
        // overdrawn account is visible from the list. One query for the page.
        $money    = collect($paginator->items())->filter(fn (LedgerAccount $a) => $a->cash_book_type !== null);
        $balances = $this->funds->balancesCents($money->pluck('id')->all());
        $money->each(fn (LedgerAccount $a) => $a->setAttribute('balance', Bill::fromCents($balances[$a->id] ?? 0)));

        return response()->json([
            'data' => LedgerAccountResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Flat list for dropdowns.
     *
     * Payment screens pass fund_sources_only=1 (optionally with
     * cash_book_type and company_id) so they offer only the accounts money
     * moves through, for the paying company, rather than the whole chart.
     */
    public function all(): JsonResponse
    {
        // account_category_id was missing here while the service supported it,
        // so "every account under Current Assets" was unreachable from the API.
        $accounts = $this->service->all(request()->only([
            'control_account_id', 'account_category_id', 'account_type',
            'cash_book_type', 'company_id', 'fund_sources_only',
            'include_party_accounts',
        ]));

        // Payment screens show the money in each Pay From / Deposit To account.
        if (request()->boolean('fund_sources_only')) {
            $available = $this->funds->availableNow($accounts);
            $accounts->each(fn (LedgerAccount $a) => $a->setAttribute('available', $available[$a->id] ?? '0.00'));
        }

        return response()->json(['data' => LedgerAccountResource::collection($accounts)]);
    }

    /** The cash / bank classification options, served from the enum. */
    public function cashBookTypes(): JsonResponse
    {
        return response()->json(['data' => CashBookType::options()]);
    }

    /** Lock-free preview of the code this ledger account would receive. */
    public function nextCode(): JsonResponse
    {
        $control = ControlAccount::find(request()->query('control_account_id'));

        if ($control === null) {
            return response()->json(['message' => 'A valid control_account_id is required.'], 422);
        }

        return response()->json(['data' => ['code' => $this->service->nextCode($control)]]);
    }

    public function store(LedgerAccountRequest $request): JsonResponse
    {
        $account = $this->service->create(LedgerAccountData::fromRequest($request));

        return response()->json(['data' => (new LedgerAccountResource($account))->toArray($request)], 201);
    }

    public function show(LedgerAccount $ledgerAccount): JsonResponse
    {
        $ledgerAccount->load(['controlAccount.category', 'company', 'bank', 'bankBranch']);

        return response()->json(['data' => (new LedgerAccountResource($ledgerAccount))->toArray(request())]);
    }

    public function update(LedgerAccountRequest $request, LedgerAccount $ledgerAccount): JsonResponse
    {
        $account = $this->service->update($ledgerAccount, LedgerAccountData::fromRequest($request));

        return response()->json(['data' => (new LedgerAccountResource($account))->toArray($request)]);
    }

    public function destroy(LedgerAccount $ledgerAccount): JsonResponse
    {
        $this->service->delete($ledgerAccount);

        return response()->json(null, 204);
    }
}
