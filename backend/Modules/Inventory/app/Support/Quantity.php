<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

/**
 * The single definition of how precise inventory numbers are.
 *
 * Stock quantities are held in a product's base UOM, which means a line entered in a
 * coarse unit can land as a small base-unit fraction (1 g of a Kg-stocked product is
 * 0.001 Kg) and a price can shrink the other way (250/Kg is 0.25/g). Getting the scale
 * or the comparison tolerance wrong in one service and right in another is how ledgers
 * silently drift apart, so every service reads them from here.
 *
 * Mirrors the schema: quantities decimal(20,6), prices decimal(20,8).
 */
final class Quantity
{
    /** Decimal places for quantities — matches decimal(20,6). */
    public const SCALE = 6;

    /** Decimal places for base-UOM prices — matches decimal(20,8). */
    public const PRICE_SCALE = 8;

    /**
     * Comparison tolerance for two quantities already in the SAME unit. Half a unit of
     * the last stored decimal place: anything smaller cannot be represented anyway, so
     * it is float noise rather than a real shortfall.
     */
    public const EPSILON = 0.0000005;

    /** Decimal places a user can actually type a quantity in (the forms use step 0.0001). */
    public const INPUT_SCALE = 4;

    private function __construct()
    {
    }

    /**
     * Tolerance for comparing a CONVERTED quantity against a stock balance.
     *
     * Converting between units rounds — 2 yd becomes 1.828801 m while the roll holds
     * 1.828799 m — so comparing at EPSILON would reject a sale of exactly the remaining
     * roll. The honest tolerance is the smallest amount the user could even express:
     * half a tick of the input precision, carried into the base unit by the same factor.
     *
     * Anything below this is not a shortfall, it is arithmetic dust.
     *
     * Floored at minSellable(): RollService::takeFrom() may absorb up to that much
     * dust into the last roll (a leftover too small to type is not stock), so any
     * comparison must forgive at least what the distribution itself is licensed to
     * snap. With a factor below 1 (Yard -> m is 0.9144) the scaled half-tick alone
     * is SMALLER than that snap, and a roll's full length auto-filled in yards
     * lands in the gap and is rejected as a mismatch it never was.
     */
    public static function toleranceFor(float $factor): float
    {
        $tick = 0.5 * (10 ** -self::INPUT_SCALE);

        return max(self::EPSILON, self::minSellable(), $tick * abs($factor));
    }

    /** The smallest quantity that is worth existing: half a tick of the input precision. */
    public static function minSellable(): float
    {
        return 0.5 * (10 ** -self::INPUT_SCALE);
    }

    /**
     * A quantity too small to type is too small to sell. Used to keep conversion dust
     * (a 0.000008 m offcut) from becoming a phantom roll nobody can ever shift.
     */
    public static function isSellable(float $value): bool
    {
        return $value >= self::minSellable();
    }

    public static function round(float $value): float
    {
        return round($value, self::SCALE);
    }

    public static function roundPrice(float $value): float
    {
        return round($value, self::PRICE_SCALE);
    }

    /** True when $a and $b are the same number at storable precision. */
    public static function equals(float $a, float $b): bool
    {
        return abs($a - $b) <= self::EPSILON;
    }

    /** True when $a is meaningfully greater than $b (not just float noise). */
    public static function greaterThan(float $a, float $b): bool
    {
        return $a - $b > self::EPSILON;
    }

    public static function isPositive(float $value): bool
    {
        return $value > self::EPSILON;
    }

    /**
     * Human-readable quantity for error messages and labels: trims the trailing zeros
     * that SCALE would otherwise print, so 45.72 reads as "45.72", not "45.720000".
     */
    public static function format(float $value): string
    {
        $formatted = number_format($value, self::SCALE, '.', ',');

        return str_contains($formatted, '.')
            ? rtrim(rtrim($formatted, '0'), '.')
            : $formatted;
    }
}
