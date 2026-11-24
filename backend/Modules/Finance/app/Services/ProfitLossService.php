<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Contracts\StockValuationSource;
use App\Support\Money\Amount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Enums\AccountType;
use Modules\Finance\Enums\JournalStatus;
use Modules\Finance\Enums\PlSection;
use Modules\Finance\Support\Ledger\JournalLineQuery;
use Modules\Finance\Support\Ledger\LedgerMoney;

/**
 * The Profit & Loss account, in the periodic (trading account) format:
 *
 *   Revenue
 *   Cost of sales  = Opening stock + Purchases + Direct costs − Closing stock
 *   Gross profit
 *   + Other income
 *   − Overheads (Administration, Selling & Distribution, Finance & Other, Other)
 *   Net profit before taxation
 *   − Taxation
 *   Net profit after taxation
 *   − Provision for reserves
 *   Net profit for the period
 *
 * ── Where each figure comes from ────────────────────────────────────────────
 * Everything except stock is the ledger: the same counted lines as the Trial
 * Balance (JournalLineQuery), so the two reports always agree. Each account
 * lands in the section of its CATEGORY (PlSection); an unclassified category
 * falls back to Other Income / Other Expenses and is flagged.
 *
 * The Purchases line is the accounts mapped to the Purchases and Purchase
 * Returns roles, wherever they sit in the chart -- the role mapping is what
 * every GRN and supplier return posts to.
 *
 * Opening and closing stock are NOT in the ledger (periodic method: a GRN
 * debits Purchases). They come from Inventory through StockValuationSource,
 * or from the user (stock count) when Inventory is off or when they override.
 *
 * ── Cost ────────────────────────────────────────────────────────────────────
 * One grouped query over the journal lines covers both periods (a conditional
 * SUM per period), one valuation call covers all four stock dates, one COUNT
 * finds unposted drafts, and (with Inventory on) one grouped query plus one
 * cost pass finds deliveries not yet invoiced. Nothing loops over journal
 * lines in PHP.
 *
 * ── Rounding ────────────────────────────────────────────────────────────────
 * Every figure is summed in exact units (LedgerMoney) and rounded once, so a
 * total always equals the sum of the lines printed above it.
 */
final class ProfitLossService
{
    public function __construct(
        private readonly StockValuationSource $stock,
        private readonly FinanceControlService $controls,
    ) {}

    /**
     * @param  array<string, mixed> $filters ProfitLossRequest::filters()
     * @return array<string, mixed>
     */
    public function build(array $filters): array
    {
        $from = CarbonImmutable::parse((string) $filters['date_from'])->startOfDay();
        $to   = CarbonImmutable::parse((string) $filters['date_to'])->startOfDay();

        $goLive = $this->controls->goLiveDate();
        $this->assertAfterGoLive($from, $goLive);

        $comparative = $this->comparative($filters, $from, $to, $goLive);
        $compare     = $comparative['available'];

        $accounts = $this->accounts($filters, $from, $to, $compare ? $comparative : null);
        $stock    = $this->stockFigures($filters, $from, $to, $compare ? $comparative : null);

        $purchaseIds = $this->purchaseAccountIds();

        // ── Sort every account into its block ──
        $blocks = [
            'revenue'      => [],
            'purchases'    => [],
            'direct_costs' => [],
            'other_income' => [],
            'taxation'     => [],
            'reserves'     => [],
        ];
        $overheads    = [];
        $unclassified = [];

        foreach ($accounts as $a) {
            $type    = AccountType::from((string) $a->account_type);
            $section = PlSection::tryFrom((string) $a->pl_section) ?? PlSection::fallbackFor($type);

            if ($section === null) {
                continue;
            }

            if ($a->pl_section === null) {
                $unclassified[(string) $a->category_name] = true;
            }

            // Income and reserves carry credit balances: shown as positive.
            $sign = $type === AccountType::Expense ? 1 : -1;
            $line = $this->line($a, $sign);

            if ($type === AccountType::Expense && isset($purchaseIds[(int) $a->ledger_account_id])) {
                $blocks['purchases'][] = $line;
                continue;
            }

            match ($section) {
                PlSection::Revenue     => $blocks['revenue'][] = $line,
                PlSection::CostOfSales => $blocks['direct_costs'][] = $line,
                PlSection::OtherIncome => $blocks['other_income'][] = $line,
                PlSection::Taxation    => $blocks['taxation'][] = $line,
                PlSection::Reserves    => $blocks['reserves'][] = $line,
                default                => $overheads[$section->value][] = $line,
            };
        }

        // ── Totals, in exact units ──
        $revenue     = $this->block($blocks['revenue'], $compare);
        $purchases   = $this->block($blocks['purchases'], $compare);
        $directCosts = $this->block($blocks['direct_costs'], $compare);
        $otherIncome = $this->block($blocks['other_income'], $compare);
        $taxation    = $this->block($blocks['taxation'], $compare);
        $reserves    = $this->block($blocks['reserves'], $compare);

        $overheadGroups = [];
        $overheadUnits  = ['amount' => 0, 'compare' => 0];

        foreach ([PlSection::Administration, PlSection::SellingDistribution, PlSection::Finance, PlSection::OtherExpenses] as $s) {
            if (empty($overheads[$s->value])) {
                continue;
            }

            $group = $this->block($overheads[$s->value], $compare);
            $overheadGroups[] = ['key' => $s->value, 'label' => $s->label(), ...$group];
            $overheadUnits['amount']  += $group['units']['amount'];
            $overheadUnits['compare'] += $group['units']['compare'];
        }

        $u = fn (array $block, string $col): int => $block['units'][$col];

        $calc = function (string $col) use ($u, $revenue, $purchases, $directCosts, $otherIncome, $taxation, $reserves, $overheadUnits, $stock): array {
            $opening = $stock['opening'][$col === 'amount' ? 'units' : 'compare_units'];
            $closing = $stock['closing'][$col === 'amount' ? 'units' : 'compare_units'];

            $cogs        = $opening + $u($purchases, $col) + $u($directCosts, $col) - $closing;
            $gross       = $u($revenue, $col) - $cogs;
            $beforeTax   = $gross + $u($otherIncome, $col) - $overheadUnits[$col];
            $afterTax    = $beforeTax - $u($taxation, $col);
            $forPeriod   = $afterTax - $u($reserves, $col);

            return compact('cogs', 'gross', 'beforeTax', 'afterTax', 'forPeriod');
        };

        $cur = $calc('amount');
        $cmp = $compare ? $calc('compare') : null;

        $pair = fn (string $key): array => [
            'amount'  => LedgerMoney::money($cur[$key]),
            'compare' => $cmp === null ? null : LedgerMoney::money($cmp[$key]),
        ];

        return [
            'period'       => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'comparative'  => $comparative,
            'go_live_date' => $goLive?->toDateString(),

            'revenue'       => $this->strip($revenue),
            'cost_of_sales' => [
                'opening_stock' => $this->stockOut($stock['opening'], $compare),
                'purchases'     => $this->strip($purchases),
                'direct_costs'  => $this->strip($directCosts),
                'closing_stock' => $this->stockOut($stock['closing'], $compare),
                'total'         => $pair('cogs'),
            ],
            'gross_profit'  => $pair('gross'),
            'other_income'  => $this->strip($otherIncome),
            'overheads'     => [
                'groups' => array_map(fn (array $g): array => $this->strip($g), $overheadGroups),
                'total'  => [
                    'amount'  => LedgerMoney::money($overheadUnits['amount']),
                    'compare' => $compare ? LedgerMoney::money($overheadUnits['compare']) : null,
                ],
            ],
            'net_before_tax' => $pair('beforeTax'),
            'taxation'       => $this->strip($taxation),
            'net_after_tax'  => $pair('afterTax'),
            'reserves'       => $this->strip($reserves),
            'net_for_period' => $pair('forPeriod'),

            'stock_source' => [
                'available' => $this->stock->isAvailable(),
                'basis'     => $this->stock->basis(),
            ],
            'warnings' => $this->warnings($filters, $from, $to, $goLive, array_keys($unclassified), $stock),
        ];
    }

    // ── Period rules ─────────────────────────────────────────────────────────

    /**
     * Before go-live the ledger is incomplete: documents confirmed then never
     * posted, so a P&L would set Inventory's stock against no sales and no
     * purchases and print a false profit. Refused, not warned.
     */
    private function assertAfterGoLive(CarbonImmutable $from, ?CarbonImmutable $goLive): void
    {
        if ($goLive !== null && $from->lessThan($goLive)) {
            throw ValidationException::withMessages([
                'date_from' => 'Finance went live on ' . $goLive->format('d-m-Y') . '. Choose a From date on or '
                    . 'after it: documents before that date never reached the ledger.',
            ]);
        }
    }

    /**
     * The comparative period: the dates given, or the period just before.
     * A run of whole calendar months compares with the same number of
     * months before (Oct vs Nov, not 31 days back); any other range with the
     * same number of days.
     *
     * @param  array<string, mixed> $filters
     * @return array{enabled: bool, available: bool, from: ?string, to: ?string, reason: ?string}
     */
    private function comparative(array $filters, CarbonImmutable $from, CarbonImmutable $to, ?CarbonImmutable $goLive): array
    {
        if (! ($filters['compare'] ?? false)) {
            return ['enabled' => false, 'available' => false, 'from' => null, 'to' => null, 'reason' => null];
        }

        if (! empty($filters['compare_from']) && ! empty($filters['compare_to'])) {
            $cFrom = CarbonImmutable::parse((string) $filters['compare_from'])->startOfDay();
            $cTo   = CarbonImmutable::parse((string) $filters['compare_to'])->startOfDay();
        } elseif ($from->day === 1 && $to->isSameDay($to->endOfMonth())) {
            $months = ($to->year - $from->year) * 12 + $to->month - $from->month + 1;
            $cFrom  = $from->subMonthsNoOverflow($months);
            $cTo    = $from->subDay();
        } else {
            $days  = (int) $from->diffInDays($to) + 1;
            $cTo   = $from->subDay();
            $cFrom = $cTo->subDays($days - 1);
        }

        $reason = null;

        if ($goLive !== null && $cFrom->lessThan($goLive)) {
            $reason = 'The comparative period starts before Finance went live on ' . $goLive->format('d-m-Y')
                . ', so its ledger is incomplete.';
        }

        return [
            'enabled'   => true,
            'available' => $reason === null,
            'from'      => $cFrom->toDateString(),
            'to'        => $cTo->toDateString(),
            'reason'    => $reason,
        ];
    }

    // ── Ledger ───────────────────────────────────────────────────────────────

    /**
     * Every P&L account with movement, one row each, the current and the
     * comparative movement side by side (net = debit − credit).
     *
     * @param  array<string, mixed>      $filters
     * @param  array<string, mixed>|null $comparative
     * @return Collection<int, object>
     */
    private function accounts(array $filters, CarbonImmutable $from, CarbonImmutable $to, ?array $comparative): Collection
    {
        // Upper bounds are "before the next day" rather than "<= the last
        // day": correct whether journal_date is stored as a date (MySQL) or
        // as a date-time string (SQLite), and still index-friendly.
        $f  = $from->toDateString();
        $t  = $to->addDay()->toDateString();
        $cf = $comparative['from'] ?? null;
        $ct = isset($comparative['to']) ? CarbonImmutable::parse($comparative['to'])->addDay()->toDateString() : null;

        $query = $this->plLines(JournalLineQuery::counted($filters))
            // One date window covering both periods; the SUMs split it.
            ->where('je.journal_date', '>=', $cf !== null && $cf < $f ? $cf : $f)
            ->where('je.journal_date', '<', $ct !== null && $ct > $t ? $ct : $t)
            ->groupBy('la.id', 'la.code', 'la.ledger_account_name', 'cat.account_type', 'cat.pl_section', 'cat.category_name')
            ->orderBy('la.code')
            ->select([
                'la.id as ledger_account_id',
                'la.code',
                'la.ledger_account_name',
                'cat.account_type',
                'cat.pl_section',
                'cat.category_name',
            ])
            ->selectRaw(
                'SUM(CASE WHEN je.journal_date >= ? AND je.journal_date < ? THEN jl.debit - jl.credit ELSE 0 END) AS net_amount',
                [$f, $t],
            );

        if ($cf !== null) {
            $query->selectRaw(
                'SUM(CASE WHEN je.journal_date >= ? AND je.journal_date < ? THEN jl.debit - jl.credit ELSE 0 END) AS net_compare',
                [$cf, $ct],
            );
        }

        return $query->get();
    }

    /**
     * Narrows counted lines to the accounts a P&L reports: income, expense,
     * and equity in the Provision for Reserves section. Joins the account,
     * control and category as la / ctrl / cat.
     */
    private function plLines(Builder $query): Builder
    {
        return $query
            ->join('fin_ledger_accounts as la', 'la.id', '=', 'jl.ledger_account_id')
            ->join('fin_control_accounts as ctrl', 'ctrl.id', '=', 'la.control_account_id')
            ->join('fin_account_categories as cat', 'cat.id', '=', 'ctrl.account_category_id')
            ->where(fn ($q) => $q
                ->whereIn('cat.account_type', [AccountType::Income->value, AccountType::Expense->value])
                ->orWhere(fn ($e) => $e
                    ->where('cat.account_type', AccountType::Equity->value)
                    ->where('cat.pl_section', PlSection::Reserves->value)));
    }

    /**
     * Ledger accounts the Purchases and Purchase Returns roles point at, for
     * any company. Setup data: a handful of rows.
     *
     * @return array<int, true>
     */
    private function purchaseAccountIds(): array
    {
        return DB::table('fin_account_defaults')
            ->whereIn('rule_key', [AccountRole::Purchases->value, AccountRole::PurchaseReturns->value])
            ->whereNotNull('ledger_account_id')
            ->pluck('ledger_account_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    /** @return array<string, mixed> */
    private function line(object $a, int $sign): array
    {
        $amount  = $sign * LedgerMoney::units($a->net_amount ?? 0);
        $compare = property_exists($a, 'net_compare') ? $sign * LedgerMoney::units($a->net_compare ?? 0) : 0;

        return [
            'ledger_account_id' => (int) $a->ledger_account_id,
            'code'              => $a->code,
            'name'              => $a->ledger_account_name,
            'units'             => ['amount' => $amount, 'compare' => $compare],
        ];
    }

    /**
     * Lines with no movement in either period are dropped (the date window
     * spans both, so an account can have movement in only one).
     *
     * @param  list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function block(array $lines, bool $compare): array
    {
        $lines = array_values(array_filter(
            $lines,
            fn (array $l): bool => $l['units']['amount'] !== 0 || ($compare && $l['units']['compare'] !== 0),
        ));

        $amount = array_sum(array_map(fn (array $l): int => $l['units']['amount'], $lines));
        $cmp    = $compare ? array_sum(array_map(fn (array $l): int => $l['units']['compare'], $lines)) : 0;

        return [
            'lines' => array_map(fn (array $l): array => [
                'ledger_account_id' => $l['ledger_account_id'],
                'code'              => $l['code'],
                'name'              => $l['name'],
                'amount'            => LedgerMoney::money($l['units']['amount']),
                'compare'           => $compare ? LedgerMoney::money($l['units']['compare']) : null,
            ], $lines),
            'total' => [
                'amount'  => LedgerMoney::money($amount),
                'compare' => $compare ? LedgerMoney::money($cmp) : null,
            ],
            'units' => ['amount' => $amount, 'compare' => $cmp],
        ];
    }

    /**
     * @param  array<string, mixed> $block
     * @return array<string, mixed> the block without its internal units
     */
    private function strip(array $block): array
    {
        unset($block['units']);

        return $block;
    }

    // ── Stock ────────────────────────────────────────────────────────────────

    /**
     * Opening stock is the stock at the end of the day before the period;
     * closing stock at the end of its last day. A figure the user typed
     * replaces the automatic one.
     *
     * @param  array<string, mixed>      $filters
     * @param  array<string, mixed>|null $comparative
     * @return array{opening: array<string, mixed>, closing: array<string, mixed>}
     */
    private function stockFigures(array $filters, CarbonImmutable $from, CarbonImmutable $to, ?array $comparative): array
    {
        $dates = [
            'opening'         => $from->subDay()->toDateString(),
            'closing'         => $to->toDateString(),
            'compare_opening' => $comparative !== null ? CarbonImmutable::parse($comparative['from'])->subDay()->toDateString() : null,
            'compare_closing' => $comparative['to'] ?? null,
        ];

        $auto = $this->stock->isAvailable()
            ? $this->stock->valuesAsAt(
                array_values(array_filter($dates)),
                $filters['company_id'] ?? null,
                $filters['location_id'] ?? null,
                $filters['allowed_company_ids'] ?? null,
            )
            : [];

        $manual = $filters['stock'] ?? [];

        $figure = function (string $key) use ($dates, $auto, $manual): array {
            $autoValue = $dates[$key] !== null && array_key_exists($dates[$key], $auto) ? (float) $auto[$dates[$key]] : null;
            $typed     = $manual[$key] ?? null;
            $value     = $typed ?? $autoValue ?? 0.0;

            return [
                'as_at'      => $dates[$key],
                'auto'       => $autoValue,
                'overridden' => $typed !== null,
                'units'      => LedgerMoney::units($value),
            ];
        };

        $pack = function (string $current, string $compare) use ($figure): array {
            $c = $figure($current);
            $p = $figure($compare);

            return [
                'as_at'              => $c['as_at'],
                'auto'               => $c['auto'],
                'overridden'         => $c['overridden'],
                'units'              => $c['units'],
                'compare_as_at'      => $p['as_at'],
                'compare_auto'       => $p['auto'],
                'compare_overridden' => $p['overridden'],
                'compare_units'      => $p['units'],
            ];
        };

        return [
            'opening' => $pack('opening', 'compare_opening'),
            'closing' => $pack('closing', 'compare_closing'),
        ];
    }

    /**
     * @param  array<string, mixed> $s
     * @return array<string, mixed>
     */
    private function stockOut(array $s, bool $compare): array
    {
        return [
            'amount'             => LedgerMoney::money($s['units']),
            'compare'            => $compare ? LedgerMoney::money($s['compare_units']) : null,
            'as_at'              => $s['as_at'],
            'compare_as_at'      => $compare ? $s['compare_as_at'] : null,
            'auto'               => $s['auto'],
            'compare_auto'       => $compare ? $s['compare_auto'] : null,
            'overridden'         => $s['overridden'],
            'compare_overridden' => $compare && $s['compare_overridden'],
        ];
    }

    // ── Warnings ─────────────────────────────────────────────────────────────

    /**
     * Things that make the figures incomplete, said plainly on the report.
     *
     * @param  array<string, mixed> $filters
     * @param  list<string>         $unclassified
     * @param  array<string, mixed> $stock
     * @return list<array{code: string, message: string, documents?: list<array<string, mixed>>}>
     */
    private function warnings(
        array $filters,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?CarbonImmutable $goLive,
        array $unclassified,
        array $stock,
    ): array {
        $warnings = [];

        if ($goLive === null) {
            $warnings[] = [
                'code'    => 'go_live_not_set',
                'message' => 'The Finance go-live date is not set. Periods before Finance went live have no sales or '
                    . 'purchases in the ledger and will show a false profit.',
            ];
        }

        if (! $this->stock->isAvailable() && ! ($stock['opening']['overridden'] && $stock['closing']['overridden'])) {
            $warnings[] = [
                'code'    => 'stock_manual',
                'message' => 'Inventory is not active: enter opening and closing stock from your stock count.',
            ];
        }

        if ($unclassified !== []) {
            sort($unclassified);
            $warnings[] = [
                'code'    => 'unclassified_categories',
                'message' => 'No P&L section set for: ' . implode(', ', $unclassified)
                    . '. Shown under Other Income / Other Expenses. Set it on Account Categories.',
            ];
        }

        $untagged = empty($filters['location_id']) ? 0 : $this->untaggedLineCount($filters, $from, $to);

        if ($untagged > 0) {
            $warnings[] = [
                'code'    => 'lines_without_location',
                'message' => "{$untagged} income / expense journal line(s) in this period have no location and are "
                    . 'not included for this location. Clear the Location filter to see the whole company.',
            ];
        }

        $uninvoiced = $this->uninvoicedWarning($filters, $from, $to);

        if ($uninvoiced !== null) {
            $warnings[] = $uninvoiced;
        }

        $drafts = $this->draftCount($filters, $from, $to);

        if ($drafts > 0) {
            $warnings[] = [
                'code'    => 'draft_journals',
                'message' => "{$drafts} draft journal(s) in this period are not posted and are not included.",
            ];
        }

        return $warnings;
    }

    /**
     * Goods delivered in the period but not invoiced by its end: their cost is
     * in Cost of Sales (the stock went down) while their sale is not in the
     * ledger, so gross profit is understated until they are invoiced.
     *
     * @param  array<string, mixed> $filters
     * @return array{code: string, message: string, documents: list<array{document_no: string, date: string, cost: float}>}|null
     */
    private function uninvoicedWarning(array $filters, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        if (! $this->stock->isAvailable()) {
            return null;
        }

        $deliveries = $this->stock->uninvoicedDeliveries(
            $from->toDateString(),
            $to->toDateString(),
            $filters['company_id'] ?? null,
            $filters['location_id'] ?? null,
            $filters['allowed_company_ids'] ?? null,
        );

        if ($deliveries === []) {
            return null;
        }

        $count = count($deliveries);
        $cost  = LedgerMoney::money(array_sum(array_map(fn (array $d): int => LedgerMoney::units($d['cost']), $deliveries)));

        $numbers = array_column(array_slice($deliveries, 0, 5), 'document_no');
        $list    = implode(', ', $numbers) . ($count > 5 ? ' and ' . ($count - 5) . ' more' : '');

        $one     = $count === 1;
        $message = sprintf(
            '%d %s in this period %s not invoiced by %s (%s), cost %s. %s cost is in Cost of Sales but %s revenue '
                . 'is not, so gross profit is understated. Invoice %s with a date within this period.',
            $count,
            $one ? 'delivery' : 'deliveries',
            $one ? 'is' : 'are',
            $to->format('d-m-Y'),
            $list,
            number_format($cost, Amount::decimals()),
            $one ? 'Its' : 'Their',
            $one ? 'its' : 'their',
            $one ? 'it' : 'them',
        );

        return ['code' => 'uninvoiced_deliveries', 'message' => $message, 'documents' => $deliveries];
    }

    /**
     * Counted P&L lines in the period with no location: a location filter
     * leaves them out. Same account scope as accounts(), same company access,
     * with the location filter itself lifted.
     *
     * @param array<string, mixed> $filters
     */
    private function untaggedLineCount(array $filters, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $this->plLines(JournalLineQuery::counted(['location_id' => null] + $filters))
            ->whereNull('jl.location_id')
            ->where('je.journal_date', '>=', $from->toDateString())
            ->where('je.journal_date', '<', $to->addDay()->toDateString())
            ->count();
    }

    /** Draft journals dated in the period that touch the company / location asked for. */
    private function draftCount(array $filters, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $query = DB::table('fin_journal_entries as je')
            ->where('je.status', JournalStatus::Draft->value)
            ->whereNull('je.deleted_at')
            ->where('je.journal_date', '>=', $from->toDateString())
            ->where('je.journal_date', '<', $to->addDay()->toDateString());

        $allowed  = $filters['allowed_company_ids'] ?? null;
        $company  = $filters['company_id'] ?? null;
        $location = $filters['location_id'] ?? null;

        if ($allowed !== null || $company !== null || $location !== null) {
            $query->whereExists(function ($q) use ($allowed, $company, $location): void {
                $q->selectRaw('1')->from('fin_journal_lines as jl')->whereColumn('jl.journal_entry_id', 'je.id');
                JournalLineQuery::restrictToCompanies($q, 'jl.company_id', $allowed);

                if ($company !== null) {
                    $q->where('jl.company_id', $company);
                }

                if ($location !== null) {
                    $q->where('jl.location_id', $location);
                }
            });
        }

        return $query->count();
    }
}
