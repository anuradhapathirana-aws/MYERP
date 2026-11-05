<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use App\DTOs\TaxBreakdown;

/** What a purchase line costs once its discount and tax code are applied. */
final class PurchaseLinePrice
{
    public function __construct(
        public readonly float        $unitPrice,       // before tax, per the line's UOM
        public readonly ?float       $unitPriceIncl,   // as typed, when the invoice quotes tax-inclusive
        public readonly float        $lineTotal,       // what the supplier is owed for the line
        public readonly float        $taxAmount,
        public readonly float        $recoverableTax,  // claimable as Input VAT
        public readonly float        $effectiveRate,   // total tax as % of the net, for display
        public readonly TaxBreakdown $breakdown,
    ) {}
}
