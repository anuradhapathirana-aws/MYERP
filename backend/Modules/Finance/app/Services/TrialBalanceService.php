<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Enums\AccountType;
use Modules\Finance\Enums\JournalStatus;

/**
 * The Trial Balance -- and the proof that the ledger is sound.
 *
 * It is a query, not a table. There is no stored balance anywhere in Finance:
 * every figure here is derived from fin_journal_lines on demand. That is the
 * whole reason a new document type never needs a schema change, and the reason
 * a balance can never silently disagree with the entries behind it.
 *
 * The same query shape, with different grouping, is what later produces the
 * General Ledger, Balance Sheet, Profit & Loss and the aged reports.
 *
 * ── Two pairs of columns, and why ────────────────────────────────────────────
 * debit_total / credit_total are GROSS movement -- everything that ever hit
 * the account. debit_balance / credit_balance are the NET position, shown on
 * whichever side the account actually lands. A Trial Balance is conventionally
 * the net form, but the gross figures are what let someone see that an account
 * with a zero balance still had activity.
 *
 * ── Brought forward / carried forward ────────────────────────────────────────
 * Every row also carries opening (b/f: everything before date_from), the
 * period's gross debits and credits, and closing (c/f = opening + period). The
 * six-column view on the screen is built from these. The debit_balance /
 * credit_balance pair stays PERIOD-only, so the standard view is unchanged.
 *
 * With $filters['detailed'] an account that has an opening balance but no
 * movement in the period is listed too (has_movement = false), because its
 * balance still carries forward. Without it the rows are movement-only, as
 * they always were.
 *
 * ── Portability ──────────────────────────────────────────────────────────────
 * SUM and GROUP BY only, with every grouped column named explicitly. No
 * database-specific syntax, because the test suite runs on SQLite and
 * production runs on MySQL, and a report that works on one but not the other
 * is worse than no report.
 */
final class TrialBalanceService
{
    /**
     * @param  array<string, mixed> $filters  date_from, date_to, company_id, location_id, detailed
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     totals: array{debit: float, credit: float, difference: float, balanced: bool},
     *     detail_totals: array<string, float|bool>
     * }
     */
    public function build(array $filters = []): array
    {
        $detailed = filter_var($filters['detailed'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // ── The period ──
        $period = $this->base($filters);

        if (! empty($filters['date_from'])) {
            $period->where('je.journal_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $period->where('je.journal_date', '<=', $filters['date_to']);
        }

        $periodRecords = $this->grouped($period)->keyBy('ledger_account_id');

        // ── Brought forward: everything before date_from ──
        $openingRecords = collect();

        if (! empty($filters['date_from'])) {
            $openingRecords = $this->grouped(
                $this->base($filters)->where('je.journal_date', '<', $filters['date_from']),
            )->keyBy('ledger_account_id');
        }

        $accounts = $detailed
            ? $periodRecords->union($openingRecords)
            : $periodRecords;

        $rows   = [];
        $totals = [
            'debit' => 0.0, 'credit' => 0.0,
            'opening_debit' => 0.0, 'opening_credit' => 0.0,
            'period_debit'  => 0.0, 'period_credit'  => 0.0,
            'closing_debit' => 0.0, 'closing_credit' => 0.0,
        ];

        foreach ($accounts->sortBy('code') as $id => $r) {
            $p = $periodRecords->get($id);
            $o = $openingRecords->get($id);

            $debitTotal  = round((float) ($p->debit_total ?? 0), 2);
            $creditTotal = round((float) ($p->credit_total ?? 0), 2);
            $net         = round($debitTotal - $creditTotal, 2);
            $opening     = $o === null ? 0.0 : round((float) $o->debit_total - (float) $o->credit_total, 2);
            $closing     = round($opening + $net, 2);

            // The net lands on exactly one side. An account that nets to zero
            // shows zero on both, but still appears, because its gross
            // movement is information.
            $row = [
                'ledger_account_id'     => (int) $id,
                'code'                  => $r->code,
                'ledger_account_name'   => $r->ledger_account_name,
                'control_account_code'  => $r->control_account_code,
                'control_account_name'  => $r->control_account_name,
                'account_category_code' => $r->account_category_code,
                'category_name'         => $r->category_name,
                ...$this->type((string) $r->account_type),
                'has_movement'          => $p !== null,
                'debit_total'           => $debitTotal,
                'credit_total'          => $creditTotal,
                'debit_balance'         => $this->dr($net),
                'credit_balance'        => $this->cr($net),
                'opening'               => $opening,
                'opening_debit'         => $this->dr($opening),
                'opening_credit'        => $this->cr($opening),
                'closing'               => $closing,
                'closing_debit'         => $this->dr($closing),
                'closing_credit'        => $this->cr($closing),
            ];

            // The standard columns only count accounts with period movement,
            // exactly as before the b/f columns existed.
            if ($p !== null) {
                $totals['debit']  += $row['debit_balance'];
                $totals['credit'] += $row['credit_balance'];
            }

            $totals['opening_debit']  += $row['opening_debit'];
            $totals['opening_credit'] += $row['opening_credit'];
            $totals['period_debit']   += $debitTotal;
            $totals['period_credit']  += $creditTotal;
            $totals['closing_debit']  += $row['closing_debit'];
            $totals['closing_credit'] += $row['closing_credit'];

            $rows[] = $row;
        }

        $totals     = array_map(fn (float $v): float => round($v, 2), $totals);
        $difference = round($totals['debit'] - $totals['credit'], 2);

        return [
            'rows'   => $rows,
            'totals' => [
                'debit'      => $totals['debit'],
                'credit'     => $totals['credit'],
                'difference' => $difference,
                // If this is ever false, something wrote to the tables without
                // going through JournalPostingService. It is the single
                // cheapest health check on the whole ledger.
                'balanced'   => abs($difference) <= 0.01,
            ],
            // Each Dr/Cr pair must agree on its own; any one that does not
            // points at the period (opening, movement, closing) that is broken.
            'detail_totals' => [
                'opening_debit'  => $totals['opening_debit'],
                'opening_credit' => $totals['opening_credit'],
                'period_debit'   => $totals['period_debit'],
                'period_credit'  => $totals['period_credit'],
                'closing_debit'  => $totals['closing_debit'],
                'closing_credit' => $totals['closing_credit'],
                'balanced'       => abs($totals['opening_debit'] - $totals['opening_credit']) <= 0.01
                    && abs($totals['period_debit'] - $totals['period_credit']) <= 0.01
                    && abs($totals['closing_debit'] - $totals['closing_credit']) <= 0.01,
            ],
        ];
    }

    /** Counted lines, with company and location applied but no dates. */
    private function base(array $filters): Builder
    {
        $query = DB::table('fin_journal_lines as jl')
            ->join('fin_journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('fin_ledger_accounts as la', 'la.id', '=', 'jl.ledger_account_id')
            ->join('fin_control_accounts as ctrl', 'ctrl.id', '=', 'la.control_account_id')
            ->join('fin_account_categories as cat', 'cat.id', '=', 'ctrl.account_category_id')
            // Posted AND reversed. A reversal adds a mirror journal rather than
            // retracting the original, so both sides have to be counted or the
            // reversal would invert the balance it was meant to cancel.
            ->whereIn('je.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereNull('je.deleted_at');

        // Filtered on the LINE, not the header: the line is authoritative for
        // reporting, so a voucher whose lines span locations reports correctly.
        if (! empty($filters['company_id'])) {
            $query->where('jl.company_id', (int) $filters['company_id']);
        }

        if (! empty($filters['location_id'])) {
            $query->where('jl.location_id', (int) $filters['location_id']);
        }

        return $query;
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function grouped(Builder $query): \Illuminate\Support\Collection
    {
        return $query
            ->groupBy(
                'la.id',
                'la.code',
                'la.ledger_account_name',
                'ctrl.code',
                'ctrl.control_account_name',
                'cat.code',
                'cat.category_name',
                'cat.account_type',
            )
            ->orderBy('la.code')
            ->get([
                'la.id as ledger_account_id',
                'la.code',
                'la.ledger_account_name',
                'ctrl.code as control_account_code',
                'ctrl.control_account_name',
                'cat.code as account_category_code',
                'cat.category_name',
                'cat.account_type',
                DB::raw('SUM(jl.debit) as debit_total'),
                DB::raw('SUM(jl.credit) as credit_total'),
            ]);
    }

    /** @return array<string, mixed> */
    private function type(string $value): array
    {
        $type = AccountType::tryFrom($value);

        return [
            'account_type'       => $type?->value,
            'account_type_label' => $type?->label(),
            'normal_balance'     => $type?->normalBalance(),
            'is_balance_sheet'   => $type?->isBalanceSheet(),
        ];
    }

    private function dr(float $net): float
    {
        return $net > 0 ? $net : 0.0;
    }

    private function cr(float $net): float
    {
        return $net < 0 ? abs($net) : 0.0;
    }
}
