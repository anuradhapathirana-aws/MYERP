<?php

declare(strict_types=1);

namespace Modules\Finance\DTOs;

use App\DTOs\TaxBreakdownLine;

/**
 * One priced bill line. amount + taxAmount = lineTotal to the cent; the line's
 * account takes lineTotal - recoverableTax, Input Tax Recoverable the rest.
 */
final class BillLinePrice
{
    /** @param list<TaxBreakdownLine> $taxLines */
    public function __construct(
        public readonly float  $amount,
        public readonly ?float $amountIncl,
        public readonly float  $taxAmount,
        public readonly float  $lineTotal,
        public readonly float  $recoverableTax,
        public readonly array  $taxLines,
        /** False for an Import VAT line, whose rate and base the CusDec does not give us. */
        public readonly bool   $rateKnown,
    ) {}
}
