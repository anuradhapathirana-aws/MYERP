<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

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
 * ── Portability ──────────────────────────────────────────────────────────────
 * SUM and GROUP BY only, with every grouped column named explicitly. No
 * database-specific syntax, because the test suite runs on SQLite and
 * production runs on MySQL, and a report that works on one but not the other
 * is worse than no report.
 */
final class TrialBalanceService
{
    /**
     * @param  array<string, mixed> $filters  date_from, date_to, company_id, location_id
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     totals: array{debit: float, credit: float, difference: float, balanced: bool}
     * }
     */
    public function build(array $filters = []): array
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

        if (! empty($filters['date_from'])) {
            $query->where('je.journal_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('je.journal_date', '<=', $filters['date_to']);
        }

        // Filtered on the LINE, not the header: the line is authoritative for
        // reporting, so a voucher whose lines span locations reports correctly.
        if (! empty($filters['company_id'])) {
            $query->where('jl.company_id', (int) $filters['company_id']);
        }

        if (! empty($filters['location_id'])) {
            $query->where('jl.location_id', (int) $filters['location_id']);
        }

        $records = $query
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

        $rows        = [];
        $totalDebit  = 0.0;
        $totalCredit = 0.0;

        foreach ($records as $r) {
            $debitTotal  = round((float) $r->debit_total, 2);
            $creditTotal = round((float) $r->credit_total, 2);
            $net         = round($debitTotal - $creditTotal, 2);

            // The net lands on exactly one side. An account that nets to zero
            // shows zero on both, but still appears, because its gross
            // movement is information.
            $debitBalance  = $net > 0 ? $net : 0.0;
            $creditBalance = $net < 0 ? abs($net) : 0.0;

            $totalDebit  += $debitBalance;
            $totalCredit += $creditBalance;

            $type = AccountType::tryFrom((string) $r->account_type);

            $rows[] = [
                'ledger_account_id'     => (int) $r->ledger_account_id,
                'code'                  => $r->code,
                'ledger_account_name'   => $r->ledger_account_name,
                'control_account_code'  => $r->control_account_code,
                'control_account_name'  => $r->control_account_name,
                'account_category_code' => $r->account_category_code,
                'category_name'         => $r->category_name,
                'account_type'          => $type?->value,
                'account_type_label'    => $type?->label(),
                'normal_balance'        => $type?->normalBalance(),
                'is_balance_sheet'      => $type?->isBalanceSheet(),
                'debit_total'           => $debitTotal,
                'credit_total'          => $creditTotal,
                'debit_balance'         => $debitBalance,
                'credit_balance'        => $creditBalance,
            ];
        }

        $totalDebit  = round($totalDebit, 2);
        $totalCredit = round($totalCredit, 2);
        $difference  = round($totalDebit - $totalCredit, 2);

        return [
            'rows'   => $rows,
            'totals' => [
                'debit'      => $totalDebit,
                'credit'     => $totalCredit,
                'difference' => $difference,
                // If this is ever false, something wrote to the tables without
                // going through JournalPostingService. It is the single
                // cheapest health check on the whole ledger.
                'balanced'   => abs($difference) <= 0.01,
            ],
        ];
    }
}
