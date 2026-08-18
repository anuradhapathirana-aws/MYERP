<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

/**
 * The single definition of how money is written down.
 *
 * Amounts and quantities look identical once they are printed — 1,250.00 is either a
 * weight or a price, and only the currency tells them apart. Every document the system
 * prints therefore says which. Sibling to Quantity: that one owns how precise a number
 * is, this one owns what the number means.
 *
 * Single-currency by design. The deployment trades in one currency, configured in
 * config/config.php, so a client in another country changes .env and nothing else.
 */
final class Money
{
    private function __construct()
    {
    }

    /** ISO code for printed documents and amount-in-words lines — 'LKR'. */
    public static function code(): string
    {
        return (string) config('inventory.currency.code', 'LKR');
    }

    /** Short symbol for dense tables and on-screen tags — 'Rs'. */
    public static function symbol(): string
    {
        return (string) config('inventory.currency.symbol', 'Rs');
    }

    /** Decimal places money is presented in. Storage precision is a separate concern (Quantity). */
    public static function decimals(): int
    {
        return (int) config('inventory.currency.decimals', 2);
    }

    /**
     * The number alone: "1,234.56".
     *
     * For columns whose header already carries the currency — repeating "Rs" down every
     * cell of a fifty-line invoice is noise, not information.
     */
    public static function number(float $value): string
    {
        return number_format($value, self::decimals(), '.', ',');
    }

    /** A standalone amount that must be unambiguous on its own: "Rs 1,234.56". */
    public static function format(float $value): string
    {
        return self::symbol() . ' ' . self::number($value);
    }

    /** Column-header suffix, so the cells below can stay bare: "Unit Price (LKR)". */
    public static function label(string $heading): string
    {
        return $heading . ' (' . self::code() . ')';
    }
}
