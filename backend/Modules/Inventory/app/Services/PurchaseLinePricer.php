<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\DTOs\TaxBreakdown;
use App\Models\TaxCode;
use App\Services\TaxCalculator;
use DateTimeInterface;
use Modules\Inventory\DTOs\PurchaseLinePrice;

/**
 * Prices one purchase line: quantity x price, less the trade discount, then
 * the line's tax code. Used by the GRN today; written for any purchase
 * document (a PO or a supplier bill can price its lines the same way).
 *
 * Tax is charged on the amount AFTER the discount — the amount actually being
 * sold. (The pre-tax-code formula charged it on the gross; no line ever carried
 * a %, so nothing posted that way.)
 *
 * With no tax code the line is priced exactly as before: gross less discount,
 * unrounded, no tax.
 */
class PurchaseLinePricer
{
    public function __construct(private readonly TaxCalculator $calculator) {}

    /**
     * @param float $price  per the line's UOM — before tax, or tax-inclusive when $inclusive
     */
    public function price(
        float $quantity,
        float $price,
        float $discountPct,
        ?TaxCode $code,
        bool $inclusive,
        ?DateTimeInterface $on = null,
    ): PurchaseLinePrice {
        $afterDiscount = $quantity * $price * (1 - $discountPct / 100);

        if ($code === null) {
            return new PurchaseLinePrice(
                unitPrice:      $price,
                unitPriceIncl:  $inclusive ? $price : null,
                lineTotal:      $afterDiscount,
                taxAmount:      0.0,
                recoverableTax: 0.0,
                effectiveRate:  0.0,
                breakdown:      TaxBreakdown::none($afterDiscount),
            );
        }

        $breakdown = $this->calculator->compute($code, $afterDiscount, $inclusive, $on);

        return new PurchaseLinePrice(
            // The stored unit price is always before tax: stock value, batch cost,
            // costing and the last-price lookup all read it that way.
            unitPrice:      $inclusive ? round($this->calculator->priceBeforeTax($code, $price, $on), 4) : $price,
            unitPriceIncl:  $inclusive ? $price : null,
            lineTotal:      $breakdown->total(),
            taxAmount:      $breakdown->taxTotal(),
            recoverableTax: $breakdown->recoverable(),
            effectiveRate:  $breakdown->effectiveRate(),
            breakdown:      $breakdown,
        );
    }
}
