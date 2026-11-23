<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Support\Money\Amount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Finance\DTOs\LedgerSelection;
use Modules\Finance\Enums\AccountType;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Support\Ledger\JournalLineQuery;
use Modules\Finance\Support\Ledger\LedgerMoney;
use Modules\Finance\Support\Ledger\ReportHeader;

/**
 * Ledger Details: the General Ledger report over a SELECTION of accounts.
 *
 *   report()     one row per account (opening, period Dr / Cr, closing,
 *                number of lines) plus grand totals, paginated by account.
 *                Two grouped queries, whatever the number of accounts.
 *   printable()  the same accounts with every line, for the PDF, in either
 *                the ledger (list) or T-account layout.
 *
 * The lines of one account are NOT built here. The screen opens each account
 * through the existing account-transactions endpoint, and the PDF asks
 * AccountTransactionsService::statement() / tAccount(). One engine draws every
 * ledger in the system, so the Trial Balance popup, the account cards and the
 * PDFs can never disagree about a balance.
 *
 * The counted-lines rule (posted + reversed, line-level company / location) is
 * JournalLineQuery's, the same one the Trial Balance uses.
 */
final class LedgerDetailsService
{
    public const ACCOUNTS_PER_PAGE = 25;

    /** The PDF is one document; past these, narrow the selection or dates. */
    public const MAX_PRINT_ACCOUNTS = 300;
    public const MAX_PRINT_ROWS     = AccountTransactionsService::MAX_STATEMENT_ROWS;

    public function __construct(private readonly AccountTransactionsService $transactions)
    {
    }

    /**
     * What the selection controls offer. Unlike the chart-of-accounts
     * dropdowns this includes party accounts and inactive accounts, and an
     * archived account as long as it still has history: a report has to be
     * able to reach every balance the Trial Balance shows.
     *
     * A company-restricted user is offered only group-wide accounts and those
     * of their own companies.
     *
     * @param  list<int>|null $allowedCompanyIds null = unrestricted
     * @return array<string, list<array<string, mixed>>>
     */
    public function options(?array $allowedCompanyIds = null): array
    {
        $accounts = JournalLineQuery::restrictToCompanies(DB::table('fin_ledger_accounts as la'), 'la.company_id', $allowedCompanyIds)
            ->where(fn (Builder $q) => $q
                ->whereNull('la.deleted_at')
                ->orWhereExists(fn (Builder $e) => $e
                    ->select(DB::raw(1))
                    ->from('fin_journal_lines as jl')
                    ->whereColumn('jl.ledger_account_id', 'la.id')))
            ->orderBy('la.code')
            ->get(['la.id', 'la.code', 'la.ledger_account_name', 'la.control_account_id', 'la.party_type', 'la.is_active', 'la.deleted_at'])
            ->map(fn (object $a): array => [
                'id'                 => (int) $a->id,
                'code'               => (string) $a->code,
                'name'               => (string) $a->ledger_account_name,
                'control_account_id' => (int) $a->control_account_id,
                'is_party'           => $a->party_type !== null,
                'is_active'          => (bool) $a->is_active && $a->deleted_at === null,
            ])
            ->all();

        $controls = DB::table('fin_control_accounts')
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->get(['id', 'code', 'control_account_name', 'account_category_id'])
            ->map(fn (object $c): array => [
                'id'                  => (int) $c->id,
                'code'                => (string) $c->code,
                'name'                => (string) $c->control_account_name,
                'account_category_id' => (int) $c->account_category_id,
            ])
            ->all();

        $categories = DB::table('fin_account_categories')
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->get(['id', 'code', 'category_name', 'account_type'])
            ->map(fn (object $c): array => [
                'id'           => (int) $c->id,
                'code'         => (string) $c->code,
                'name'         => (string) $c->category_name,
                'account_type' => (string) $c->account_type,
            ])
            ->all();

        return [
            'accounts'         => $accounts,
            'control_accounts' => $controls,
            'categories'       => $categories,
            'account_types'    => array_map(
                fn (AccountType $t): array => ['value' => $t->value, 'label' => $t->label()],
                AccountType::cases(),
            ),
        ];
    }

    /**
     * @param  array<string, mixed> $filters date_from, date_to, company_id, location_id
     * @return array<string, mixed>
     */
    public function report(LedgerSelection $selection, array $filters, int $page = 1): array
    {
        $accounts = $this->accountBalances($selection, $filters);
        $total    = count($accounts);
        $lastPage = max(1, (int) ceil($total / self::ACCOUNTS_PER_PAGE));
        $page     = min(max(1, $page), $lastPage);

        return [
            'accounts'     => array_slice($accounts, ($page - 1) * self::ACCOUNTS_PER_PAGE, self::ACCOUNTS_PER_PAGE),
            'totals'       => $this->totals($accounts),
            'default_view' => $this->transactions->defaultView(),
            'meta'         => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => self::ACCOUNTS_PER_PAGE,
                'total'        => $total,
            ],
        ];
    }

    /**
     * Everything the PDF prints: each account with its lines, in the chosen
     * layout. Lines share one budget across the whole document, so a single
     * busy account cannot crowd out every other one silently: once it runs out
     * the remaining accounts still print their balances, and `truncated` says so.
     *
     * @param  array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function printable(LedgerSelection $selection, array $filters, string $view): array
    {
        $balances = $this->accountBalances($selection, $filters);
        $printed  = array_slice($balances, 0, self::MAX_PRINT_ACCOUNTS);

        // One query for every account model, with what accountInfo() reads,
        // kept in print (code) order.
        $models = LedgerAccount::withTrashed()
            ->with('controlAccount.category')
            ->whereIn('id', array_column($printed, 'ledger_account_id'))
            ->get()
            ->keyBy('id');

        $accounts = collect($printed)
            ->map(fn (array $row): ?LedgerAccount => $models->get($row['ledger_account_id']))
            ->filter()
            ->values();

        // Every account's lines in a fixed handful of queries, sharing one budget.
        $statements = $this->transactions->statements($accounts, $filters, self::MAX_PRINT_ROWS);

        $sections = $view === AccountTransactionsService::VIEW_T_ACCOUNT
            ? array_map(fn (array $s): array => $this->transactions->tAccountOf($s, $filters), $statements)
            : $statements;

        $truncated = in_array(true, array_column($statements, 'truncated'), true);

        return [
            'header'             => ReportHeader::for($filters),
            'period'             => ReportHeader::period($filters),
            'view'               => $view,
            'sections'           => $sections,
            'totals'             => $this->totals($balances),
            'truncated'          => $truncated,
            'accounts_truncated' => count($balances) > count($printed),
            'max_accounts'       => self::MAX_PRINT_ACCOUNTS,
            'max_rows'           => self::MAX_PRINT_ROWS,
        ];
    }

    /**
     * One row per selected account, by code: opening, the period's gross
     * debits and credits, closing, and how many lines the period holds.
     *
     * Accounts with neither an opening balance nor movement are left out
     * unless the selection asks for them, and then only live accounts: an
     * archived account with no history is not worth a line.
     *
     * @param  array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    private function accountBalances(LedgerSelection $selection, array $filters): array
    {
        $selected = $this->selectedAccounts($selection, $filters['allowed_company_ids'] ?? null);
        $ids      = (clone $selected)->select('la.id');

        $period  = $this->grouped(JournalLineQuery::inPeriod(JournalLineQuery::counted($filters), $filters), $ids);
        $before  = JournalLineQuery::beforePeriod(JournalLineQuery::counted($filters), $filters);
        $opening = $before === null ? collect() : $this->grouped($before, $ids);

        $rows = [];

        foreach ($selected->orderBy('la.code')->get($this->accountColumns()) as $a) {
            $id = (int) $a->id;
            $p  = $period->get($id);
            $o  = $opening->get($id);

            // Exact units, rounded once (LedgerMoney), exactly as the drill-down does.
            $openingUnits = $o === null ? 0 : LedgerMoney::units($o->debit) - LedgerMoney::units($o->credit);
            $debitUnits   = LedgerMoney::units($p->debit ?? 0);
            $creditUnits  = LedgerMoney::units($p->credit ?? 0);
            $openingNet   = LedgerMoney::money($openingUnits);
            $hasActivity  = $p !== null || $openingNet != 0.0;
            $isLive       = (bool) $a->is_active && $a->deleted_at === null;

            if (! $hasActivity && ! ($selection->includeNoActivity && $isLive)) {
                continue;
            }

            $type = AccountType::tryFrom((string) $a->account_type);

            $rows[] = [
                'ledger_account_id'    => $id,
                'code'                 => (string) $a->code,
                'ledger_account_name'  => (string) $a->ledger_account_name,
                'control_account_code' => (string) $a->control_account_code,
                'control_account_name' => (string) $a->control_account_name,
                'category_name'        => (string) $a->category_name,
                'account_type'         => $type?->value,
                'account_type_label'   => $type?->label(),
                'normal_balance'       => $type?->normalBalance(),
                'is_party'             => $a->party_type !== null,
                'is_active'            => $isLive,
                'line_count'           => (int) ($p->line_count ?? 0),
                'opening'              => $openingNet,
                'period_debit'         => LedgerMoney::money($debitUnits),
                'period_credit'        => LedgerMoney::money($creditUnits),
                'period_net'           => LedgerMoney::money($debitUnits - $creditUnits),
                'closing'              => LedgerMoney::money($openingUnits + $debitUnits - $creditUnits),
            ];
        }

        return $rows;
    }

    /**
     * The accounts the selection names, joined to their control account and
     * category. Every criterion narrows; none set means the whole chart.
     * Another company's own accounts (its bank, its cash) are never selected
     * for a restricted user.
     *
     * @param list<int>|null $allowedCompanyIds
     */
    private function selectedAccounts(LedgerSelection $s, ?array $allowedCompanyIds): Builder
    {
        return JournalLineQuery::restrictToCompanies(DB::table('fin_ledger_accounts as la'), 'la.company_id', $allowedCompanyIds)
            ->join('fin_control_accounts as ctrl', 'ctrl.id', '=', 'la.control_account_id')
            ->join('fin_account_categories as cat', 'cat.id', '=', 'ctrl.account_category_id')
            ->when($s->accountIds !== [], fn (Builder $q) => $q->whereIn('la.id', $s->accountIds))
            ->when($s->codeFrom !== null, fn (Builder $q) => $q->where('la.code', '>=', $s->codeFrom))
            // "To 2" reads as "up to and including everything starting 2",
            // which is what an accountant means by a code range.
            ->when($s->codeTo !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('la.code', '<=', $s->codeTo)
                ->orWhere('la.code', 'like', addcslashes($s->codeTo, '%_\\') . '%')))
            ->when($s->controlAccountId !== null, fn (Builder $q) => $q->where('la.control_account_id', $s->controlAccountId))
            ->when($s->accountCategoryId !== null, fn (Builder $q) => $q->where('ctrl.account_category_id', $s->accountCategoryId))
            ->when($s->accountType !== null, fn (Builder $q) => $q->where('cat.account_type', $s->accountType->value))
            ->when(! $s->includeParties, fn (Builder $q) => $q->whereNull('la.party_type'));
    }

    /** @return list<string> */
    private function accountColumns(): array
    {
        return [
            'la.id', 'la.code', 'la.ledger_account_name', 'la.party_type', 'la.is_active', 'la.deleted_at',
            'ctrl.code as control_account_code', 'ctrl.control_account_name',
            'cat.category_name', 'cat.account_type',
        ];
    }

    /**
     * Debit, credit and line count per account, for the selected accounts only.
     *
     * @return Collection<int, object> keyed by ledger account id
     */
    private function grouped(Builder $lines, Builder $accountIds): Collection
    {
        return $lines
            ->whereIn('jl.ledger_account_id', $accountIds)
            ->groupBy('jl.ledger_account_id')
            ->get([
                'jl.ledger_account_id',
                DB::raw('SUM(jl.debit) as debit'),
                DB::raw('SUM(jl.credit) as credit'),
                DB::raw('COUNT(*) as line_count'),
            ])
            ->keyBy(fn (object $r): int => (int) $r->ledger_account_id);
    }

    /**
     * Grand totals over every selected account, not just the page. Each pair
     * is on the side the balance lands, as the detailed Trial Balance shows it.
     *
     * @param  list<array<string, mixed>> $accounts
     * @return array<string, float|int>
     */
    private function totals(array $accounts): array
    {
        $t = [
            'opening_debit' => 0.0, 'opening_credit' => 0.0,
            'period_debit'  => 0.0, 'period_credit'  => 0.0,
            'closing_debit' => 0.0, 'closing_credit' => 0.0,
        ];

        foreach ($accounts as $a) {
            $t['opening_debit']  += max(0.0, $a['opening']);
            $t['opening_credit'] += max(0.0, -$a['opening']);
            $t['period_debit']   += $a['period_debit'];
            $t['period_credit']  += $a['period_credit'];
            $t['closing_debit']  += max(0.0, $a['closing']);
            $t['closing_credit'] += max(0.0, -$a['closing']);
        }

        return [
            ...array_map(fn (float $v): float => Amount::round($v), $t),
            'account_count' => count($accounts),
            'line_count'    => array_sum(array_column($accounts, 'line_count')),
        ];
    }
}
