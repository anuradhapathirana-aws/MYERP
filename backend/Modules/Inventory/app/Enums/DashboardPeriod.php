<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The period the dashboard's KPI cards cover. Every range ends today, so a
 * figure is always "so far" — and the comparison range is the same length of
 * the previous period (1st–12th of last month against 1st–12th of this one),
 * never a whole month against part of one.
 */
enum DashboardPeriod: string
{
    case Today = 'today';
    case Week  = 'week';
    case Month = 'month';
    case Year  = 'year';

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function range(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        $from = match ($this) {
            self::Today => $today,
            // Monday, whatever the app locale says.
            self::Week  => $today->startOfWeek(CarbonInterface::MONDAY),
            self::Month => $today->startOfMonth(),
            self::Year  => $today->startOfYear(),
        };

        return [$from, $today];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function previousRange(?CarbonImmutable $today = null): array
    {
        [$from, $to] = $this->range($today);

        return match ($this) {
            self::Today => [$from->subDay(), $to->subDay()],
            self::Week  => [$from->subWeek(), $to->subWeek()],
            self::Month => [$from->subMonthNoOverflow(), $to->subMonthNoOverflow()],
            self::Year  => [$from->subYearNoOverflow(), $to->subYearNoOverflow()],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::Week  => 'This Week',
            self::Month => 'This Month',
            self::Year  => 'This Year',
        };
    }
}
