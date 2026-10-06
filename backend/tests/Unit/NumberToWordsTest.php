<?php

declare(strict_types=1);

namespace Tests\Unit;

use Modules\Inventory\Support\NumberToWords;
use PHPUnit\Framework\TestCase;

class NumberToWordsTest extends TestCase
{
    public function test_whole_amounts_read_as_before(): void
    {
        $this->assertSame('LKR : ONE HUNDRED AND EIGHTY THOUSAND ONLY', NumberToWords::convert(180000, 'LKR'));
        $this->assertSame('LKR : ZERO ONLY', NumberToWords::convert(0, 'LKR'));
    }

    public function test_cents_are_included(): void
    {
        $this->assertSame(
            'LKR : ONE THOUSAND TWO HUNDRED AND FIFTY AND SEVENTY FIVE CENTS ONLY',
            NumberToWords::convert(1250.75, 'LKR'),
        );
        $this->assertSame('LKR : TEN AND FIVE CENTS ONLY', NumberToWords::convert(10.05, 'LKR'));
        $this->assertSame('LKR : ZERO AND FIFTY CENTS ONLY', NumberToWords::convert(0.5, 'LKR'));
    }

    public function test_words_match_the_two_decimal_figure_printed_beside_them(): void
    {
        // Stored at 4 decimals, printed rounded to 2 — the words follow the printed figure.
        $this->assertSame('LKR : ONE THOUSAND TWO HUNDRED AND FIFTY ONLY', NumberToWords::convert(1249.996, 'LKR'));
        $this->assertSame('LKR : NINETY NINE AND NINETY NINE CENTS ONLY', NumberToWords::convert(99.994, 'LKR'));
    }
}
