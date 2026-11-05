<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TaxCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic of a tax code, with rates already resolved. Mirrored by the
 * frontend's utils/taxCalc.js — a case added here belongs there too.
 */
class TaxCalculatorTest extends TestCase
{
    private const VAT      = ['ref' => 'VAT', 'rate' => 18.0, 'compound' => false, 'recoverable' => true];
    private const SSCL     = ['ref' => 'SSCL_PUR', 'rate' => 2.5, 'compound' => false, 'recoverable' => false];
    private const VAT_ON_TOP = ['ref' => 'VAT', 'rate' => 18.0, 'compound' => true, 'recoverable' => true];

    public function test_vat_is_added_on_top_of_a_price_before_tax(): void
    {
        $b = TaxCalculator::calculate([self::VAT], 10000, inclusive: false);

        $this->assertSame(10000.0, $b->base);
        $this->assertSame(1800.0, $b->taxTotal());
        $this->assertSame(11800.0, $b->total());
        $this->assertSame(1800.0, $b->recoverable());
        $this->assertSame(0.0, $b->nonRecoverable());
        $this->assertSame(18.0, $b->effectiveRate());
    }

    public function test_a_vat_inclusive_price_is_split_back_into_goods_and_vat(): void
    {
        $b = TaxCalculator::calculate([self::VAT], 11800, inclusive: true);

        $this->assertSame(10000.0, $b->base);
        $this->assertSame(1800.0, $b->taxTotal());
        $this->assertSame(11800.0, $b->total());
    }

    public function test_vat_on_top_of_sscl_charges_vat_on_price_plus_sscl(): void
    {
        $b = TaxCalculator::calculate([self::SSCL, self::VAT_ON_TOP], 10000, inclusive: false);

        $this->assertSame(250.0, $b->lines[0]->amount);
        $this->assertSame(10250.0, $b->lines[1]->base);
        $this->assertSame(1845.0, $b->lines[1]->amount);
        $this->assertSame(12095.0, $b->total());
        $this->assertSame(1845.0, $b->recoverable(), 'Only VAT is claimable.');
        $this->assertSame(250.0, $b->nonRecoverable(), 'SSCL is part of the cost.');
    }

    public function test_an_inclusive_compound_total_splits_back_exactly(): void
    {
        $b = TaxCalculator::calculate([self::SSCL, self::VAT_ON_TOP], 12095, inclusive: true);

        $this->assertSame(10000.0, $b->base);
        $this->assertSame(250.0, $b->lines[0]->amount);
        $this->assertSame(1845.0, $b->lines[1]->amount);
    }

    /** An awkward inclusive total must still add up to the cent, as the supplier printed it. */
    public function test_inclusive_rounding_never_loses_a_cent(): void
    {
        foreach ([1234.0, 999.99, 0.05, 77777.77, 1.0] as $printed) {
            $b = TaxCalculator::calculate([self::SSCL, self::VAT_ON_TOP], $printed, inclusive: true);

            $this->assertSame(round($printed, 2), $b->total(), "Total for {$printed}");
        }
    }

    public function test_a_code_without_taxes_leaves_the_amount_untouched(): void
    {
        $b = TaxCalculator::calculate([], 5000, inclusive: true);

        $this->assertSame(5000.0, $b->base);
        $this->assertSame(0.0, $b->taxTotal());
        $this->assertSame([], $b->lines);
    }

    public function test_tax_that_cannot_be_claimed_is_all_cost(): void
    {
        $vatNotRegistered = ['recoverable' => false] + self::VAT;

        $b = TaxCalculator::calculate([$vatNotRegistered], 10000, inclusive: false);

        $this->assertSame(0.0, $b->recoverable());
        $this->assertSame(1800.0, $b->nonRecoverable());
    }

    public function test_the_inclusive_factor_compounds(): void
    {
        $this->assertEqualsWithDelta(1.18, TaxCalculator::inclusiveFactor([self::VAT]), 1e-12);
        $this->assertEqualsWithDelta(1.2095, TaxCalculator::inclusiveFactor([self::SSCL, self::VAT_ON_TOP]), 1e-12);
    }
}
