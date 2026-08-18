<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

/**
 * Converts a monetary amount into words for printed documents
 * (e.g. GRN/invoice "amount in words" lines). Whole-currency only —
 * fractional cents are dropped, matching how these lines are used on
 * printed business documents in this system.
 */
final class NumberToWords
{
    private const ONES = [
        '', 'ONE', 'TWO', 'THREE', 'FOUR', 'FIVE', 'SIX', 'SEVEN', 'EIGHT', 'NINE',
        'TEN', 'ELEVEN', 'TWELVE', 'THIRTEEN', 'FOURTEEN', 'FIFTEEN', 'SIXTEEN',
        'SEVENTEEN', 'EIGHTEEN', 'NINETEEN',
    ];

    private const TENS = [
        '', '', 'TWENTY', 'THIRTY', 'FORTY', 'FIFTY', 'SIXTY', 'SEVENTY', 'EIGHTY', 'NINETY',
    ];

    private const SCALES = ['', 'THOUSAND', 'MILLION', 'BILLION', 'TRILLION'];

    public static function convert(float $amount, string $currency = ''): string
    {
        $whole = (int) floor(abs($amount));
        $words = $whole === 0 ? 'ZERO' : self::convertWhole($whole);
        $prefix = $currency !== '' ? strtoupper($currency) . ' : ' : '';

        return trim($prefix . $words . ' ONLY');
    }

    private static function convertWhole(int $number): string
    {
        $groups = [];
        while ($number > 0) {
            $groups[] = $number % 1000;
            $number = intdiv($number, 1000);
        }

        $parts = [];
        for ($i = count($groups) - 1; $i >= 0; $i--) {
            if ($groups[$i] === 0) {
                continue;
            }
            $scale = self::SCALES[$i] ?? '';
            $parts[] = trim(self::convertHundreds($groups[$i]) . ($scale !== '' ? ' ' . $scale : ''));
        }

        return implode(' ', $parts);
    }

    private static function convertHundreds(int $number): string
    {
        $hundred = intdiv($number, 100);
        $remainder = $number % 100;

        $words = $hundred > 0 ? self::ONES[$hundred] . ' HUNDRED' : '';

        if ($remainder > 0) {
            $words .= ($words !== '' ? ' AND ' : '') . self::convertTens($remainder);
        }

        return $words;
    }

    private static function convertTens(int $number): string
    {
        if ($number < 20) {
            return self::ONES[$number];
        }

        $tens = intdiv($number, 10);
        $ones = $number % 10;

        return trim(self::TENS[$tens] . ($ones > 0 ? ' ' . self::ONES[$ones] : ''));
    }
}
