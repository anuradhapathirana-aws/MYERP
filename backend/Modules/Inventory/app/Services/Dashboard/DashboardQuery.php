<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Portable SQL fragments for the dashboard. Production is MySQL; the test
 * suite runs on SQLite, which has no DATE_FORMAT and stores model-cast dates
 * with a time part.
 */
final class DashboardQuery
{
    /** "YYYY-MM" of a date column, in the connection's dialect. */
    public static function monthKey(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'pgsql'  => "to_char({$column}, 'YYYY-MM')",
            default  => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }

    /**
     * Inclusive day range written as [from, to + 1 day): BETWEEN 'd' AND 'd'
     * would miss a value stored as 'd 00:00:00'.
     */
    public static function inDays(Builder $query, string $column, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query
            ->where($column, '>=', $from->toDateString())
            ->where($column, '<', $to->addDay()->toDateString());
    }
}
