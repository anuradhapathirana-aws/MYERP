<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

/**
 * The single definition of how VAT relates a Before Tax price to an After Tax one.
 *
 * Prices are stored Before Tax — price list, SO lines — and the invoice decides
 * whether VAT is shown (Tax invoice) or carried inside the price (Non Tax invoice).
 * Sibling to Money: the rate comes from config/config.php (tax.default_vat_pct).
 */
final class Vat
{
    private function __construct()
    {
    }

    /** The rate a line takes when its costing names none — 18 in Sri Lanka. */
    public static function defaultPct(): float
    {
        return (float) config('inventory.tax.default_vat_pct', 18);
    }

    /** Before Tax → After Tax, unrounded. */
    public static function gross(float $net, ?float $pct = null): float
    {
        return $net * (1 + ($pct ?? self::defaultPct()) / 100);
    }

    /** After Tax → Before Tax, unrounded — only for prices that are known to carry VAT. */
    public static function net(float $gross, ?float $pct = null): float
    {
        return $gross / (1 + ($pct ?? self::defaultPct()) / 100);
    }
}
