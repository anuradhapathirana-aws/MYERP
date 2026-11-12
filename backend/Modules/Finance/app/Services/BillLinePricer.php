<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\DTOs\TaxBreakdown;
use App\DTOs\TaxBreakdownLine;
use App\Models\Tax;
use App\Models\TaxCode;
use App\Services\TaxCalculator;
use App\Services\TaxService;
use DateTimeInterface;
use Modules\Finance\DTOs\BillLinePrice;
use Modules\Finance\Enums\ChargeTreatment;

/**
 * Prices one bill line with the same tax engine the GRN uses (TaxCalculator),
 * so a supplier's VAT is split the same way on every purchase document.
 *
 *   ordinary line   amount (+ tax code)  -> before tax, tax, total, claimable
 *   Import VAT line the amount IS the tax (from the CusDec): all of it is
 *                   claimable while VAT is on for us, none of it while VAT is off
 *                   (then it is simply cost, like duty)
 */
class BillLinePricer
{
    public function __construct(
        private readonly TaxCalculator $calculator,
        private readonly TaxService    $taxes,
    ) {}

    /**
     * @param string $amount as typed: before tax, or tax-inclusive when $inclusive
     */
    public function price(
        string $amount,
        ?TaxCode $code,
        ?ChargeTreatment $treatment,
        bool $inclusive,
        DateTimeInterface $on,
    ): BillLinePrice {
        $typed = round((float) $amount, 2);

        if ($treatment === ChargeTreatment::RecoverableTax) {
            $claimable = $this->taxes->isActive(Tax::VAT, $on);

            return new BillLinePrice(
                amount:         $typed,
                amountIncl:     null,
                taxAmount:      0.0,
                lineTotal:      $typed,
                recoverableTax: $claimable ? $typed : 0.0,
                // Kept for the VAT return; the CusDec gives the amount, not the base.
                taxLines:       [new TaxBreakdownLine(Tax::VAT, 0.0, 0.0, $typed, $claimable)],
                rateKnown:      false,
            );
        }

        $breakdown = $code === null
            ? TaxBreakdown::none($typed)
            : $this->calculator->compute($code, $typed, $inclusive, $on);

        return new BillLinePrice(
            amount:         $breakdown->base,
            amountIncl:     $inclusive && $code !== null ? $typed : null,
            taxAmount:      $breakdown->taxTotal(),
            lineTotal:      $breakdown->total(),
            recoverableTax: $breakdown->recoverable(),
            taxLines:       $breakdown->lines,
            rateKnown:      true,
        );
    }
}
