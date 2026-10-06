<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

/**
 * Converts a monetary amount into words for printed documents
 * (e.g. GRN/invoice "amount in words" lines), cents included:
 * 1250.75 → "LKR : ONE THOUSAND TWO HUNDRED AND FIFTY AND SEVENTY FIVE CENTS ONLY".
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
        // Rounded to the cent first — the same 2 decimals the printed figure beside it
        // shows — so the words always agree with that figure (1249.996 prints as
        // 1,250.00 and reads as one thousand two hundred and fifty).
        $totalCents = (int) round(abs($amount) * 100);
        $whole      = intdiv($totalCents, 100);
        $cents      = $totalCents % 100;

        $words = $whole === 0 ? 'ZERO' : self::convertWhole($whole);
        if ($cents > 0) {
            $words .= ' AND ' . self::convertTens($cents) . ' CENTS';
        }

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
