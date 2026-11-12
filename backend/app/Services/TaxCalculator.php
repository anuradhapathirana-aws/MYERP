<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\TaxBreakdown;
use App\DTOs\TaxBreakdownLine;
use App\Models\TaxCode;
use DateTimeInterface;

/**
 * Applies a tax code to an amount. The one tax engine every document uses —
 * GRN lines today, supplier bills and landed costs next — so all of them split
 * tax the same way.
 *
 * Rates come from the Taxes master for the document's date. A supplier charges
 * its taxes whatever our own registration says, so the CONFIGURED rate is used;
 * but VAT is only claimable while VAT is switched on for us (an unregistered
 * business cannot claim input VAT, so it becomes cost).
 *
 * Compound components are charged on the base plus every earlier tax — SSCL
 * first, then VAT on (price + SSCL), as Sri Lankan invoices show it.
 */
class TaxCalculator
{
    public function __construct(private readonly TaxService $taxes) {}

    /**
     * @param float $amount    before tax when $inclusive is false; the tax-inclusive
     *                         total (as printed on the supplier invoice) when true
     */
    public function compute(?TaxCode $code, float $amount, bool $inclusive, ?DateTimeInterface $on = null): TaxBreakdown
    {
        if ($code === null) {
            return TaxBreakdown::none($amount);
        }

        return self::calculate($this->resolve($code, $on), $amount, $inclusive);
    }

    /**
     * A tax-inclusive unit price taken back to before tax, unrounded — what a
     * document stores as its unit price when the supplier quotes VAT inclusive.
     */
    public function priceBeforeTax(?TaxCode $code, float $inclusivePrice, ?DateTimeInterface $on = null): float
    {
        return $code === null ? $inclusivePrice : $inclusivePrice / self::inclusiveFactor($this->resolve($code, $on));
    }

    /**
     * The code's components with rates and the claimable rule resolved for a date.
     *
     * @return list<array{ref: string, rate: float, compound: bool, recoverable: bool}>
     */
    public function resolve(TaxCode $code, ?DateTimeInterface $on = null): array
    {
        $code->loadMissing('components');

        return $code->components->map(fn ($c) => [
            'ref'         => $c->tax_code_ref,
            'rate'        => $this->taxes->configuredRate($c->tax_code_ref, $on),
            'compound'    => (bool) $c->is_compound,
            'recoverable' => (bool) $c->is_recoverable && $this->taxes->isActive($c->tax_code_ref, $on),
        ])->values()->all();
    }

    /**
     * The arithmetic alone, with rates already resolved. Mirrored by the
     * frontend's utils/taxCalc.js for the live preview; keep the two in step.
     *
     * @param list<array{ref: string, rate: float, compound: bool, recoverable: bool}> $components
     */
    public static function calculate(array $components, float $amount, bool $inclusive): TaxBreakdown
    {
        if ($components === []) {
            return TaxBreakdown::none($amount);
        }

        $base = $inclusive
            ? round($amount / self::inclusiveFactor($components), 2)
            : round($amount, 2);

        $lines = self::linesOn($components, $base);

        // Inclusive: the printed total is the truth. Rounding each tax can leave
        // a cent between base + taxes and that total; it goes on the last tax,
        // so the line still agrees with the supplier's invoice.
        if ($inclusive) {
            $drift = round(round($amount, 2) - $base - array_sum(array_map(fn ($l) => $l->amount, $lines)), 2);

            if ($drift !== 0.0) {
                $last          = array_key_last($lines);
                $l             = $lines[$last];
                $lines[$last]  = new TaxBreakdownLine($l->taxCodeRef, $l->rate, $l->base, round($l->amount + $drift, 2), $l->isRecoverable);
            }
        }

        return new TaxBreakdown($base, $lines);
    }

    /**
     * @param  list<array{ref: string, rate: float, compound: bool, recoverable: bool}> $components
     * @return list<TaxBreakdownLine>
     */
    private static function linesOn(array $components, float $base): array
    {
        $lines   = [];
        $earlier = 0.0;

        foreach ($components as $c) {
            $on     = $c['compound'] ? round($base + $earlier, 2) : $base;
            $amount = round($on * $c['rate'] / 100, 2);

            $lines[]  = new TaxBreakdownLine($c['ref'], (float) $c['rate'], $on, $amount, $c['recoverable']);
            $earlier += $amount;
        }

        return $lines;
    }

    /**
     * What 1 before tax becomes after every tax, unrounded — dividing an
     * inclusive total by it gives the base.
     *
     * @param list<array{ref: string, rate: float, compound: bool, recoverable: bool}> $components
     */
    public static function inclusiveFactor(array $components): float
    {
        $earlier = 0.0;

        foreach ($components as $c) {
            $earlier += ($c['compound'] ? 1 + $earlier : 1) * $c['rate'] / 100;
        }

        return 1 + $earlier;
    }
}
