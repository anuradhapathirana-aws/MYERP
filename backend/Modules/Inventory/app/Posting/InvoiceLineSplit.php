<?php

declare(strict_types=1);

namespace Modules\Inventory\Posting;

/**
 * Splits an invoice line's amount into VAT, SSCL and net revenue.
 *
 * Shared by InvoiceJournal and CustomerReturnJournal so a return reverses an
 * invoice by exactly the same arithmetic it was posted with.
 *
 *   Tax line      line_total = gross - gross x d% + gross x t%
 *                 VAT = gross x t% = line_total x t / (100 - d + t)
 *   Non-Tax line  VAT is inside the price at vat_inclusive_pct (p):
 *                 VAT = line_total x p / (100 + p)
 *   SSCL          inside the before-VAT price at sscl_pct (s), as the costing
 *                 builds it: SSCL = (line_total - VAT) x s / (100 + s)
 *
 * Unrounded on purpose: callers sum the parts and round once per document.
 */
final class InvoiceLineSplit
{
    /** @return array{vat: float, sscl: float} */
    public static function of(
        float $amount,
        float $taxPct,
        float $discountPct,
        ?float $vatInclusivePct,
        ?float $ssclPct,
    ): array {
        $vat = 0.0;

        if ($taxPct > 0) {
            $base = 100 - $discountPct + $taxPct;
            $vat  = $base > 0 ? $amount * $taxPct / $base : 0.0;
        } elseif ($vatInclusivePct !== null && $vatInclusivePct > 0) {
            $vat = $amount * $vatInclusivePct / (100 + $vatInclusivePct);
        }

        $sscl = $ssclPct !== null && $ssclPct > 0
            ? ($amount - $vat) * $ssclPct / (100 + $ssclPct)
            : 0.0;

        return ['vat' => $vat, 'sscl' => $sscl];
    }
}
