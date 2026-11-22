<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Services\AmountPrecision;

/**
 * The one way to round a money AMOUNT before it is stored or compared.
 *
 *   Amount::round(123.4567)      -> 123.46 at the system setting (2 today)
 *   Amount::equals($a, $b)       -> true within half the smallest unit
 *   Amount::tolerance()          -> 0.005 at 2 decimals
 *
 * Replaces the scattered round($x, 2) / MONEY_SCALE constants, so the Amount
 * decimals setting (AmountPrecision) is honoured everywhere at once. Never use
 * it for unit prices, unit costs, rates or quantities: those keep their own
 * precision.
 */
final class Amount
{
    private function __construct()
    {
    }

    public static function decimals(): int
    {
        return app(AmountPrecision::class)->decimals();
    }

    public static function round(float|int|string|null $value): float
    {
        return round((float) ($value ?? 0), self::decimals());
    }

    /** Half the smallest amount the system can store: anything below it is float noise. */
    public static function tolerance(): float
    {
        return 0.5 / (10 ** self::decimals());
    }

    public static function equals(float|int|string|null $a, float|int|string|null $b): bool
    {
        return abs(self::round($a) - self::round($b)) < self::tolerance();
    }
}
