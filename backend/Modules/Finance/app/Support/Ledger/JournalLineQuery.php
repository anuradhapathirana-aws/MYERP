<?php

declare(strict_types=1);

namespace Modules\Finance\Support\Ledger;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Enums\JournalStatus;

/**
 * The one definition of "a journal line that counts", shared by every ledger
 * report (Trial Balance, account drill-down, Ledger Details).
 *
 *   - Posted AND reversed journals. A reversal adds a mirror journal rather
 *     than retracting the original, so both sides have to be counted or the
 *     reversal would invert the balance it was meant to cancel.
 *   - Soft-deleted journals never count.
 *   - Company and location are filtered on the LINE, not the header: the line
 *     is authoritative for reporting, so a voucher whose lines span locations
 *     reports correctly.
 *   - Company ACCESS: `allowed_company_ids` (from LedgerFilterRequest, via
 *     CompanyAccessService) keeps a restricted user to their companies' lines
 *     plus group-wide lines with no company -- the same rule as the Journal
 *     Vouchers list and the Dashboard. Null means unrestricted.
 *
 * Aliases are fixed: `jl` for fin_journal_lines, `je` for fin_journal_entries.
 * Callers add their own joins and grouping on top.
 *
 * Every report building on this agrees by construction, which is what lets
 * the drill-down promise "period movement equals the Trial Balance row".
 */
final class JournalLineQuery
{
    /**
     * Counted lines, with company access, company and location applied but no dates.
     *
     * @param array<string, mixed> $filters allowed_company_ids, company_id, location_id
     */
    public static function counted(array $filters = []): Builder
    {
        $query = DB::table('fin_journal_lines as jl')
            ->join('fin_journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('je.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereNull('je.deleted_at');

        self::restrictToCompanies($query, 'jl.company_id', $filters['allowed_company_ids'] ?? null);

        if (! empty($filters['company_id'])) {
            $query->where('jl.company_id', (int) $filters['company_id']);
        }

        if (! empty($filters['location_id'])) {
            $query->where('jl.location_id', (int) $filters['location_id']);
        }

        return $query;
    }

    /**
     * Keeps rows of the allowed companies plus group-wide rows (NULL company).
     * Used on journal lines and on ledger accounts alike. Null = no restriction.
     *
     * @param list<int>|null $allowed
     */
    public static function restrictToCompanies(Builder $query, string $column, ?array $allowed): Builder
    {
        if ($allowed === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->whereIn($column, $allowed)->orWhereNull($column));
    }

    /**
     * Narrows to the period: date_from .. date_to, either end open.
     *
     * @param array<string, mixed> $filters date_from, date_to
     */
    public static function inPeriod(Builder $query, array $filters): Builder
    {
        if (! empty($filters['date_from'])) {
            $query->where('je.journal_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('je.journal_date', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * Narrows to everything before date_from: the brought-forward balance.
     * Null when there is no From date, because then nothing is brought forward.
     *
     * @param array<string, mixed> $filters date_from
     */
    public static function beforePeriod(Builder $query, array $filters): ?Builder
    {
        if (empty($filters['date_from'])) {
            return null;
        }

        return $query->where('je.journal_date', '<', $filters['date_from']);
    }
}
