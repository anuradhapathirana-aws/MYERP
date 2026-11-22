<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use App\Support\Journal\PostingRole;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Finance\Enums\JournalStatus;
use Modules\Finance\Enums\JournalType;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Support\Ledger\JournalLineQuery;
use Modules\Finance\Support\Ledger\LedgerMoney;
use Modules\Finance\Support\Ledger\ReportHeader;

/**
 * One account's ledger, as the Trial Balance drill-down shows it:
 *
 *   Opening balance   everything before date_from (zero when there is none)
 *   transactions      each line in the period, with a running Dr/Cr balance
 *   Closing balance   opening + the period's movement
 *
 * Same rules as TrialBalanceService, so the period movement here always equals
 * the row the user clicked: posted AND reversed journals both count, the line
 * (not the header) carries company and location, and nothing is stored.
 *
 * ── Running balance across pages ─────────────────────────────────────────────
 * Pages are 50 lines. Page N starts at the opening balance plus the sum of
 * every earlier line in the same order, worked out by the server, so page 3
 * begins where page 2 ended. meta.balance_bf / balance_cf are those two
 * figures, printed as "Balance b/f" and "Balance c/f" like a paper ledger. That order (journal_date, journal id, line id) is
 * total: two pages can never disagree about which line came first.
 *
 * ── Search and source filters ────────────────────────────────────────────────
 * A running balance over a filtered subset is not a balance of anything. When
 * either filter is set the rows come back with balance = null, and the summary
 * still shows the account's true opening, movement and closing.
 *
 * ── Cross-module lookups ─────────────────────────────────────────────────────
 * Document numbers and party names live in Inventory tables. Each is read
 * behind Schema::hasTable, like ChequeStatusRegistry, so a Finance-only
 * installation still gets its ledger, just without those names.
 */
final class AccountTransactionsService
{
    public const PER_PAGE = 50;

    /** The PDF is the whole period in one go; past this, narrow the dates. */
    public const MAX_STATEMENT_ROWS = 3000;

    /**
     * The company's default ledger layout (Finance Settings, super admin).
     * Each user's own last choice is kept in their browser on top of this.
     */
    public const SETTING_LEDGER_VIEW = 'finance.ledger_view';
    public const VIEW_LIST           = 'list';
    public const VIEW_T_ACCOUNT      = 't_account';
    public const VIEWS               = [self::VIEW_LIST, self::VIEW_T_ACCOUNT];

    private const COLUMNS = [
        'jl.id', 'jl.journal_entry_id', 'jl.ledger_account_id', 'jl.debit', 'jl.credit', 'jl.description',
        'jl.party_type', 'jl.party_id',
        'je.journal_no', 'je.journal_date', 'je.journal_type', 'je.status', 'je.narration',
        'je.source_type', 'je.source_id', 'je.reverses_journal_id',
    ];

    /** Header source_type value standing for "no document": a hand-entered voucher. */
    public const SOURCE_MANUAL = 'manual';

    /**
     * Label, table and number column of each document a journal can come from.
     *
     * @var array<string, array{0:string, 1:string, 2:string}>
     */
    private const SOURCES = [
        PostingRole::SOURCE_GRN                 => ['GRN',              'inv_goods_received_notes', 'grn_no'],
        PostingRole::SOURCE_INVOICE             => ['Invoice',          'inv_invoices',             'invoice_no'],
        PostingRole::SOURCE_SUPPLIER_PAYMENT    => ['Supplier Payment', 'inv_supplier_payments',    'payment_no'],
        PostingRole::SOURCE_CUSTOMER_RECEIPT    => ['Customer Receipt', 'inv_customer_receipts',    'receipt_no'],
        PostingRole::SOURCE_CUSTOMER_RETURN     => ['Customer Return',  'inv_customer_returns',     'return_no'],
        PostingRole::SOURCE_SUPPLIER_RETURN     => ['Supplier Return',  'inv_supplier_returns',     'return_no'],
        PostingRole::SOURCE_CHEQUE_RETURN       => ['Cheque Return',    'inv_cheque_returns',       'document_no'],
        PostingRole::SOURCE_BILL                => ['Bill',             'fin_bills',                'bill_no'],
        PostingRole::SOURCE_BILL_PAYMENT        => ['Bill Payment',     'fin_bill_payments',        'payment_no'],
        PostingRole::SOURCE_BILL_PAYMENT_RETURN => ['Bill Payment Return', 'fin_bill_payments',     'payment_no'],
        PostingRole::SOURCE_BANK_DEPOSIT        => ['Bank Deposit',     'fin_bank_deposits',        'deposit_no'],
    ];

    /** @var array<string, array{0:string, 1:string}> party_type => [table, name column] */
    private const PARTIES = [
        PostingRole::PARTY_SUPPLIER => ['inv_supplier_masters', 'supplier_name'],
        PostingRole::PARTY_CUSTOMER => ['inv_customer_masters', 'customer_name'],
        PostingRole::PARTY_EMPLOYEE => ['core_employees',       'employee_name'],
    ];

    /** @var array<string, bool> */
    private array $tables = [];

    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * @param  array<string, mixed> $filters date_from, date_to, company_id, location_id, search, source_type
     * @return array<string, mixed>
     */
    public function build(LedgerAccount $account, array $filters, int $page = 1): array
    {
        $page      = max(1, $page);
        $accountId = (int) $account->id;
        $filtered  = ! empty($filters['search']) || ! empty($filters['source_type']);

        // ── Summary: always the whole account, never the filtered subset ──
        [$summary, $openingUnits] = $this->accountSummary($accountId, $filters);

        // ── The page ──
        $listQuery = $this->period($accountId, $filters);
        $this->applyListFilters($listQuery, $filters);

        $total  = (clone $listQuery)->count();
        $offset = ($page - 1) * self::PER_PAGE;

        $records = $this->ordered($listQuery)
            ->offset($offset)
            ->limit(self::PER_PAGE)
            ->get(self::COLUMNS);

        // The running balance, in exact units (LedgerMoney). Null while a
        // filter is set: a balance over a subset is not a balance of anything.
        $running = null;

        if (! $filtered) {
            $running = $openingUnits;

            if ($offset > 0) {
                $before   = $this->ordered($this->period($accountId, $filters))
                    ->limit($offset)
                    ->select(['jl.debit', 'jl.credit']);
                [$d, $c]  = $this->unitSums(DB::query()->fromSub($before, 'prior'), 'prior');
                $running += $d - $c;
            }
        }

        // Brought forward onto this page: the opening on page 1, the previous
        // page's carried-forward figure on every page after it.
        $balanceBf = $running;

        [$rows, $running] = $this->present($records, $running, $this->lookups($records));

        return [
            'account'      => $this->accountInfo($account),
            'default_view' => $this->defaultView(),
            'summary'      => [...$summary, 'is_filtered' => $filtered],
            'source_types' => $this->sourceTypes($accountId, $filters),
            'rows'         => $rows,
            'meta'         => [
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($total / self::PER_PAGE)),
                'per_page'     => self::PER_PAGE,
                'total'        => $total,
                // Null while a filter is set: a b/f over a subset means nothing.
                'balance_bf'   => $balanceBf === null ? null : LedgerMoney::money($balanceBf),
                'balance_cf'   => $running === null ? null : LedgerMoney::money($running),
            ],
        ];
    }

    /**
     * The whole period as a balanced T-account, for the PDF. Search and the
     * document filter are ignored: a T-account only balances over every entry.
     *
     * Debit side:  To Balance b/d (debit opening), each debit "To <contra>",
     *              To Balance c/d (when the account closes in credit)
     * Credit side: By Balance b/d (credit opening), each credit "By <contra>",
     *              By Balance c/d (when the account closes in debit)
     *
     * Both sides then total the same, and the closing reopens below the rule
     * as Balance b/d on its own side.
     *
     * @param  array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function tAccount(LedgerAccount $account, array $filters, int $limit = self::MAX_STATEMENT_ROWS): array
    {
        return [
            'header' => ReportHeader::for($filters),
            'period' => ReportHeader::period($filters),
            ...$this->tAccountOf($this->statement($account, $filters, $limit), $filters),
        ];
    }

    /**
     * A statement (see statements()) laid out as a balanced T-account: the
     * body the PDFs print, without the letterhead.
     *
     * @param  array<string, mixed> $statement
     * @param  array<string, mixed> $filters
     * @return array<string, mixed> account, summary, debit, credit, total, reopen, truncated
     */
    public function tAccountOf(array $statement, array $filters): array
    {
        [
            'summary' => ['opening' => $opening, 'period_debit' => $periodDebit, 'period_credit' => $periodCredit, 'closing' => $closing],
            'rows' => $rows,
        ] = $statement;

        $periodStart = $filters['date_from'] ?? ($rows[0]['journal_date'] ?? null);
        $periodEnd   = $filters['date_to'] ?? now()->toDateString();

        $entry = fn (array $r, string $side): array => [
            'date'        => $r['journal_date'],
            'particulars' => ($side === 'debit' ? 'To ' : 'By ') . $this->particulars($r['contra']),
            'accounts'    => count($r['contra']['accounts']) > 1 ? $r['contra']['accounts'] : [],
            'ref'         => $r['journal_no'],
            'document'    => trim($r['source_label'] . ' ' . ($r['document_no'] ?? '')),
            'narration'   => $r['narration'] ?: $r['description'],
            'amount'      => $side === 'debit' ? $r['debit'] : $r['credit'],
            'is_balance'  => false,
        ];

        $balanceLine = fn (string $prefix, ?string $date, float $amount): array => [
            'date' => $date, 'particulars' => $prefix . ' Balance b/d', 'accounts' => [], 'ref' => null,
            'document' => null, 'narration' => null, 'amount' => $amount, 'is_balance' => true,
        ];

        $debit  = [];
        $credit = [];

        if ($opening > 0) {
            $debit[] = $balanceLine('To', $periodStart, $opening);
        } elseif ($opening < 0) {
            $credit[] = $balanceLine('By', $periodStart, abs($opening));
        }

        foreach ($rows as $r) {
            if ($r['debit'] > 0) {
                $debit[] = $entry($r, 'debit');
            }

            if ($r['credit'] > 0) {
                $credit[] = $entry($r, 'credit');
            }
        }

        // Balance c/d goes on the smaller side, so both sides total the same.
        if ($closing > 0) {
            $credit[] = [...$balanceLine('By', $periodEnd, $closing), 'particulars' => 'By Balance c/d'];
        } elseif ($closing < 0) {
            $debit[] = [...$balanceLine('To', $periodEnd, abs($closing)), 'particulars' => 'To Balance c/d'];
        }

        $total = round(max(
            array_sum(array_column($debit, 'amount')),
            array_sum(array_column($credit, 'amount')),
        ), 2);

        $reopen = CarbonImmutable::parse($periodEnd)->addDay()->toDateString();

        return [
            'account'   => $statement['account'],
            'summary'   => [
                'opening'       => $opening,
                'period_debit'  => $periodDebit,
                'period_credit' => $periodCredit,
                'closing'       => $closing,
            ],
            'debit'     => $debit,
            'credit'    => $credit,
            'total'     => $total,
            // The closing, reopened below the double rule for the next period.
            'reopen'    => $closing == 0.0 ? null : [
                'side'   => $closing > 0 ? 'debit' : 'credit',
                'date'   => $reopen,
                'amount' => abs($closing),
                'particulars' => ($closing > 0 ? 'To' : 'By') . ' Balance b/d',
            ],
            'truncated' => $statement['truncated'],
        ];
    }

    /**
     * The whole period in one go, every line with its running balance: what
     * the printed ledgers (T-account PDF, Ledger Details PDF) are drawn from.
     * Search and the document filter are ignored, so the balances are real.
     *
     * $limit caps the lines read; past it `truncated` is true and the caller
     * says so on the page. Accounts with heavy traffic narrow the dates.
     *
     * @param  array<string, mixed> $filters date_from, date_to, company_id, location_id
     * @return array{
     *     account: array<string, mixed>,
     *     summary: array{opening: float, period_debit: float, period_credit: float, period_net: float, closing: float},
     *     rows: list<array<string, mixed>>,
     *     truncated: bool
     * }
     */
    public function statement(LedgerAccount $account, array $filters, int $limit = self::MAX_STATEMENT_ROWS): array
    {
        return $this->statements([$account], $filters, $limit)[0];
    }

    /**
     * statement() for many accounts at once, in a fixed number of queries
     * whatever their count: grouped balances (2), every line (1), then one
     * lookup per document type, per party type, and for the contra accounts.
     * This is what keeps a 300-account Ledger Details PDF fast.
     *
     * Lines share one $limit across all accounts, consumed in account-code
     * order (the order the PDF prints). An account whose lines did not all
     * fit is marked truncated; its balances are always complete.
     *
     * @param  iterable<LedgerAccount> $accounts
     * @param  array<string, mixed>    $filters
     * @return list<array<string, mixed>> one statement per account, in the order given
     */
    public function statements(iterable $accounts, array $filters, int $limit = self::MAX_STATEMENT_ROWS): array
    {
        $accounts = collect($accounts)->values();

        if ($accounts->isEmpty()) {
            return [];
        }

        $ids   = $accounts->map(fn (LedgerAccount $a): int => (int) $a->id)->all();
        $limit = max(0, $limit);

        $period  = $this->groupedUnits(JournalLineQuery::inPeriod(JournalLineQuery::counted($filters), $filters), $ids);
        $before  = JournalLineQuery::beforePeriod(JournalLineQuery::counted($filters), $filters);
        $opening = $before === null ? collect() : $this->groupedUnits($before, $ids);

        $records = $limit === 0 ? collect() : $this->ordered(
            JournalLineQuery::inPeriod(JournalLineQuery::counted($filters), $filters)
                ->join('fin_ledger_accounts as la', 'la.id', '=', 'jl.ledger_account_id')
                ->whereIn('jl.ledger_account_id', $ids)
                ->orderBy('la.code')
                ->orderBy('la.id'),
        )->limit($limit)->get(self::COLUMNS);

        $lookups = $this->lookups($records);
        $byId    = $records->groupBy(fn (object $r): int => (int) $r->ledger_account_id);

        return $accounts->map(function (LedgerAccount $account) use ($period, $opening, $byId, $lookups): array {
            $id = (int) $account->id;
            $p  = $period->get($id);
            $o  = $opening->get($id);

            $openingUnits = $o === null ? 0 : $o->debit - $o->credit;
            $lines        = $byId->get($id, collect())->values();

            [$rows] = $this->present($lines, $openingUnits, $lookups);

            return [
                'account'   => $this->accountInfo($account),
                'summary'   => $this->summaryOf($openingUnits, $p->debit ?? 0, $p->credit ?? 0),
                'rows'      => $rows,
                'truncated' => count($rows) < ($p->line_count ?? 0),
            ];
        })->all();
    }

    /** 'list' or 't_account': the company's default ledger layout. */
    public function defaultView(): string
    {
        $view = (string) $this->settings->get(self::SETTING_LEDGER_VIEW, self::VIEW_LIST);

        return in_array($view, self::VIEWS, true) ? $view : self::VIEW_LIST;
    }

    public function setDefaultView(string $view): string
    {
        if (! in_array($view, self::VIEWS, true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'ledger_view' => 'Choose List or T-Account.',
            ]);
        }

        $this->settings->set(self::SETTING_LEDGER_VIEW, $view);

        return $view;
    }

    /** One contra in T-account wording: the account name, or "Sundries". */
    private function particulars(array $contra): string
    {
        return match (count($contra['accounts'])) {
            0       => '—',
            1       => $contra['accounts'][0]['name'],
            default => 'Sundries',
        };
    }

    /**
     * One account's summary, plus its opening in exact units for a running balance.
     *
     * @return array{0: array<string, float>, 1: int}
     */
    private function accountSummary(int $accountId, array $filters): array
    {
        $openingUnits = 0;
        $before       = JournalLineQuery::beforePeriod($this->lines($accountId, $filters), $filters);

        if ($before !== null) {
            [$d, $c]      = $this->unitSums($before);
            $openingUnits = $d - $c;
        }

        [$debit, $credit] = $this->unitSums($this->period($accountId, $filters));

        return [$this->summaryOf($openingUnits, $debit, $credit), $openingUnits];
    }

    /**
     * Opening (b/f), period debits and credits, period net, closing (c/f).
     * Each is the exact figure rounded once, so the closing always equals
     * the last running balance.
     *
     * @return array{opening: float, period_debit: float, period_credit: float, period_net: float, closing: float}
     */
    private function summaryOf(int $openingUnits, int $debitUnits, int $creditUnits): array
    {
        return [
            'opening'       => LedgerMoney::money($openingUnits),
            'period_debit'  => LedgerMoney::money($debitUnits),
            'period_credit' => LedgerMoney::money($creditUnits),
            'period_net'    => LedgerMoney::money($debitUnits - $creditUnits),
            'closing'       => LedgerMoney::money($openingUnits + $debitUnits - $creditUnits),
        ];
    }

    /**
     * The lookups present() needs, for any set of lines, in one pass:
     * document numbers, party names and contra accounts.
     *
     * @param  Collection<int, object> $records
     * @return array{documents: array<string, array<int, string>>, parties: array<string, string>, contras: array<int, array<string, mixed>>}
     */
    private function lookups(Collection $records): array
    {
        return [
            'documents' => $this->documents($records),
            'parties'   => $this->parties($records),
            'contras'   => $this->contras($records),
        ];
    }

    /**
     * Lines as the UI and PDF show them. $running is the balance before the
     * first line in exact units, or null to leave balances out (a filtered list).
     *
     * @param  Collection<int, object> $records lines of ONE account, in order
     * @param  array<string, mixed>    $lookups from lookups()
     * @return array{0: list<array<string, mixed>>, 1: ?int} rows, balance (units) after the last row
     */
    private function present(Collection $records, ?int $running, array $lookups): array
    {
        ['documents' => $documents, 'parties' => $parties, 'contras' => $contras] = $lookups;

        $rows = [];

        foreach ($records as $r) {
            $debitUnits  = LedgerMoney::units($r->debit);
            $creditUnits = LedgerMoney::units($r->credit);
            $debit       = LedgerMoney::money($debitUnits);
            $credit      = LedgerMoney::money($creditUnits);

            if ($running !== null) {
                $running += $debitUnits - $creditUnits;
            }

            $type   = JournalType::tryFrom((string) $r->journal_type);
            $source = $this->sourceOf($r, $documents);

            $rows[] = [
                'journal_line_id'    => (int) $r->id,
                'journal_entry_id'   => (int) $r->journal_entry_id,
                'journal_no'         => $r->journal_no,
                'journal_date'       => substr((string) $r->journal_date, 0, 10),
                'journal_type'       => $type?->value,
                'journal_type_label' => $type?->label(),
                'is_reversed'        => $r->status === JournalStatus::Reversed->value,
                'source_type'        => $source['type'],
                // The document's own id, so a screen can link to it. Null for
                // a hand-entered voucher or a reversal.
                'source_id'          => $r->source_type === null ? null : (int) $r->source_id,
                'source_label'       => $source['label'],
                'document_no'        => $source['document_no'],
                'narration'          => $r->narration,
                'description'        => $r->description,
                'party_type'         => $r->party_type,
                'party_name'         => $r->party_type === null
                    ? null
                    : ($parties[$r->party_type . ':' . $r->party_id] ?? ucfirst((string) $r->party_type) . ' #' . $r->party_id),
                'contra'             => $contras[(int) $r->id] ?? ['label' => null, 'accounts' => []],
                'debit'              => $debit,
                'credit'             => $credit,
                'balance'            => $running === null ? null : LedgerMoney::money($running),
            ];
        }

        return [$rows, $running];
    }

    /** @return array<string, mixed> */
    private function accountInfo(LedgerAccount $account): array
    {
        $account->loadMissing('controlAccount.category');

        $type = $account->controlAccount?->accountType();

        return [
            'id'                   => (int) $account->id,
            'code'                 => $account->code,
            'ledger_account_name'  => $account->ledger_account_name,
            'control_account_name' => $account->controlAccount?->control_account_name,
            'category_name'        => $account->controlAccount?->category?->category_name,
            'account_type'         => $type?->value,
            'account_type_label'   => $type?->label(),
            'normal_balance'       => $type?->normalBalance(),
        ];
    }

    /** Every counted line of the account (see JournalLineQuery). */
    private function lines(int $accountId, array $filters): Builder
    {
        return JournalLineQuery::counted($filters)->where('jl.ledger_account_id', $accountId);
    }

    /** The lines inside the date range. */
    private function period(int $accountId, array $filters): Builder
    {
        return JournalLineQuery::inPeriod($this->lines($accountId, $filters), $filters);
    }

    private function applyListFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['source_type'])) {
            $filters['source_type'] === self::SOURCE_MANUAL
                ? $query->whereNull('je.source_type')
                : $query->where('je.source_type', $filters['source_type']);
        }

        if (! empty($filters['search'])) {
            $term = '%' . addcslashes((string) $filters['search'], '%_\\') . '%';

            $query->where(function (Builder $q) use ($term): void {
                $q->where('je.journal_no', 'like', $term)
                    ->orWhere('je.narration', 'like', $term)
                    ->orWhere('jl.description', 'like', $term);
            });
        }
    }

    private function ordered(Builder $query): Builder
    {
        return $query->orderBy('je.journal_date')->orderBy('je.id')->orderBy('jl.id');
    }

    /** @return array{0: int, 1: int} debit, credit in exact units */
    private function unitSums(Builder $query, string $alias = 'jl'): array
    {
        $row = $query->selectRaw("COALESCE(SUM({$alias}.debit), 0) as d, COALESCE(SUM({$alias}.credit), 0) as c")->first();

        return [LedgerMoney::units($row->d ?? 0), LedgerMoney::units($row->c ?? 0)];
    }

    /**
     * Debit, credit (exact units) and line count per account, for these accounts.
     *
     * @param  list<int> $accountIds
     * @return Collection<int, object{debit:int, credit:int, line_count:int}> keyed by account id
     */
    private function groupedUnits(Builder $lines, array $accountIds): Collection
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
            ->mapWithKeys(fn (object $r): array => [(int) $r->ledger_account_id => (object) [
                'debit'      => LedgerMoney::units($r->debit),
                'credit'     => LedgerMoney::units($r->credit),
                'line_count' => (int) $r->line_count,
            ]]);
    }

    /**
     * Document types that actually hit this account in the period, for the
     * popup's filter.
     *
     * @return list<array{value:string, label:string}>
     */
    private function sourceTypes(int $accountId, array $filters): array
    {
        return $this->period($accountId, $filters)
            ->distinct()
            ->pluck('je.source_type')
            ->map(fn (?string $t): array => $t === null
                ? ['value' => self::SOURCE_MANUAL, 'label' => 'Journal Voucher']
                : ['value' => $t, 'label' => self::SOURCES[$t][0] ?? ucwords(str_replace('_', ' ', $t))])
            ->unique('value')
            ->sortBy('label')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array<int, string>> $documents
     * @return array{type:string, label:string, document_no:?string}
     */
    private function sourceOf(object $r, array $documents): array
    {
        if ($r->source_type === null) {
            if ($r->reverses_journal_id !== null) {
                return [
                    'type'        => self::SOURCE_MANUAL,
                    'label'       => 'Reversal',
                    'document_no' => $documents['journal'][(int) $r->reverses_journal_id] ?? null,
                ];
            }

            return ['type' => self::SOURCE_MANUAL, 'label' => 'Journal Voucher', 'document_no' => null];
        }

        $type = (string) $r->source_type;

        return [
            'type'        => $type,
            'label'       => self::SOURCES[$type][0] ?? ucwords(str_replace('_', ' ', $type)),
            'document_no' => $documents[$type][(int) $r->source_id] ?? null,
        ];
    }

    /**
     * Document numbers, one query per document type on the page.
     *
     * @param  Collection<int, object> $records
     * @return array<string, array<int, string>> source_type => [id => number]
     */
    private function documents(Collection $records): array
    {
        $result = [];

        foreach ($records->whereNotNull('source_type')->groupBy('source_type') as $type => $group) {
            [, $table, $column] = self::SOURCES[$type] ?? [null, null, null];

            if ($table === null || ! $this->hasTable($table)) {
                continue;
            }

            $result[$type] = DB::table($table)
                ->whereIn('id', $this->ids($group, 'source_id'))
                ->pluck($column, 'id')
                ->mapWithKeys(fn ($no, $id): array => [(int) $id => (string) $no])
                ->all();
        }

        $reversed = $this->ids($records, 'reverses_journal_id');

        if ($reversed !== []) {
            $result['journal'] = DB::table('fin_journal_entries')
                ->whereIn('id', $reversed)
                ->pluck('journal_no', 'id')
                ->mapWithKeys(fn ($no, $id): array => [(int) $id => (string) $no])
                ->all();
        }

        return $result;
    }

    /**
     * @param  Collection<int, object> $records
     * @return array<string, string> "party_type:party_id" => name
     */
    private function parties(Collection $records): array
    {
        $result = [];

        foreach ($records->whereNotNull('party_type')->groupBy('party_type') as $type => $group) {
            [$table, $column] = self::PARTIES[$type] ?? [null, null];

            if ($table === null || ! $this->hasTable($table)) {
                continue;
            }

            DB::table($table)
                ->whereIn('id', $this->ids($group, 'party_id'))
                ->pluck($column, 'id')
                ->each(function ($name, $id) use (&$result, $type): void {
                    $result[$type . ':' . $id] = (string) $name;
                });
        }

        return $result;
    }

    /**
     * The other side of each line: the accounts on the opposite side of the
     * same journal. One account reads as its name; several as "Split".
     *
     * Each line's "other side" excludes that line's OWN account, so one call
     * serves lines of many accounts (the batched PDF) as well as one.
     *
     * @param  Collection<int, object> $records
     * @return array<int, array{label:?string, accounts:list<array{code:string, name:string}>}>
     */
    private function contras(Collection $records): array
    {
        $entryIds = $this->ids($records, 'journal_entry_id');

        if ($entryIds === []) {
            return [];
        }

        $journalLines = DB::table('fin_journal_lines as jl')
            ->join('fin_ledger_accounts as la', 'la.id', '=', 'jl.ledger_account_id')
            ->whereIn('jl.journal_entry_id', $entryIds)
            ->orderBy('jl.line_no')
            ->get(['jl.journal_entry_id', 'jl.ledger_account_id', 'jl.debit', 'jl.credit', 'la.code', 'la.ledger_account_name'])
            ->groupBy('journal_entry_id');

        $result = [];

        foreach ($records as $r) {
            $lines    = $journalLines->get($r->journal_entry_id, collect())
                ->filter(fn (object $l): bool => (int) $l->ledger_account_id !== (int) $r->ledger_account_id);
            $isDebit  = (float) $r->debit > 0;
            $opposite = $lines->filter(fn (object $l): bool => $isDebit ? (float) $l->credit > 0 : (float) $l->debit > 0);

            // A journal that only touches this account on both sides has no
            // opposite line; fall back to whatever else it touched.
            $accounts = ($opposite->isNotEmpty() ? $opposite : $lines)
                ->unique('ledger_account_id')
                ->map(fn (object $l): array => ['code' => (string) $l->code, 'name' => (string) $l->ledger_account_name])
                ->values()
                ->all();

            $result[(int) $r->id] = [
                'label'    => match (count($accounts)) {
                    0       => null,
                    1       => $accounts[0]['name'],
                    default => 'Split (' . count($accounts) . ' accounts)',
                },
                'accounts' => $accounts,
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int, object> $records
     * @return list<int>
     */
    private function ids(Collection $records, string $column): array
    {
        return $records->pluck($column)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= Schema::hasTable($table);
    }
}
