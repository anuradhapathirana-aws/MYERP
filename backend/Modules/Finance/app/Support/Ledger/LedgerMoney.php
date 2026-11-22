<?php

declare(strict_types=1);

namespace Modules\Finance\Support\Ledger;

/**
 * Exact ledger arithmetic.
 *
 * Journal amounts are stored as decimal(15,4). Adding them as floats and
 * rounding each step lets a running balance drift away from the closing
 * balance printed under it (three lines of 0.3333 + 0.0001 read 0.99 against
 * a closing of 1.00). So every ledger balance is worked in whole UNITS of
 * 0.0001 -- integers, exact -- and rounded to cents once, at the end, half
 * away from zero.
 *
 * The rule every report follows: a balance shown is the exact sum, rounded
 * once. Never a sum of rounded figures.
 */
final class LedgerMoney
{
    /** Units per currency unit: the stored scale of fin_journal_lines amounts. */
    public const UNITS = 10000;

    /** A stored amount (decimal string or number) in exact units. */
    public static function units(mixed $amount): int
    {
        return (int) round((float) ($amount ?? 0) * self::UNITS);
    }

    /** Units as money at 2 decimals, rounded half away from zero in integer arithmetic. */
    public static function money(int $units): float
    {
        $cents = intdiv(abs($units) + intdiv(self::UNITS, 200), intdiv(self::UNITS, 100));

        return ($units < 0 ? -$cents : $cents) / 100;
    }
}
