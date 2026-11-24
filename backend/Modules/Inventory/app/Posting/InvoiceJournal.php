<?php

declare(strict_types=1);

namespace Modules\Inventory\Posting;

use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use App\Support\Journal\PostingRole;
use App\Support\Money\Amount;
use Modules\Inventory\Models\Invoice;

/**
 * Turns an issued invoice into a balanced journal.
 *
 * The other half of the receivable bridge: this DEBITS Accounts Receivable, and
 * a customer receipt later credits it. Without this, a receipt would clear a
 * receivable that was never recorded.
 *
 * Posted when the invoice moves Draft -> Issued, not when it is created. A
 * draft invoice is a working document; issuing it is the act that creates the
 * claim on the customer and recognises the revenue.
 *
 * ── Two kinds of discount, only one of which reaches the ledger ─────────────
 * inv_invoice_items.discount is a TRADE discount -- a price reduction. It is
 * already inside line_total, so revenue is recognised net of it and it gets no
 * line of its own. The discount that does get a line is the SETTLEMENT discount
 * on a receipt allocation, which forgives part of an already-recorded
 * receivable. Treating the first like the second would overstate both revenue
 * and expenses by the same amount.
 *
 * ── Tax ─────────────────────────────────────────────────────────────────────
 * Tax collected from a customer is not income -- it is owed to the revenue
 * authority. Splitting it out is what makes Output Tax Payable a usable figure
 * for a return. A client who is not tax registered has 0% lines and simply
 * gets no tax line.
 *
 * A Non-Tax invoice adds no VAT on top, but its price already contains it (the
 * costing's after-tax price), so that VAT is split out the same way. SSCL sits
 * inside every price once a rate is set and goes to SSCL Payable. Both rates
 * are stamped on the line at issue -- see InvoiceLineSplit.
 */
final class InvoiceJournal
{
    public static function build(Invoice $invoice): JournalRequest
    {
        $invoice->loadMissing(['items', 'customer', 'deliveryOrder:id,location_id']);

        $tax  = 0.0;
        $sscl = 0.0;

        foreach ($invoice->items as $item) {
            $split = InvoiceLineSplit::of(
                (float) $item->line_total,
                (float) $item->tax,
                (float) $item->discount,
                $item->vat_inclusive_pct === null ? null : (float) $item->vat_inclusive_pct,
                $item->sscl_pct === null ? null : (float) $item->sscl_pct,
            );

            $tax  += $split['vat'];
            $sscl += $split['sscl'];
        }

        $tax  = Amount::round($tax);
        $sscl = Amount::round($sscl);

        // Revenue is what is left of the bill once the levies are taken out:
        // the goods net of tax and trade discount, plus transport (which
        // carries no tax of its own in this schema). Derived rather than summed
        // so the journal always balances to the grand total.
        $revenue = Amount::round((float) $invoice->grand_total - $tax - $sscl);

        $lines = [
            JournalLineRequest::debit(
                PostingRole::ACCOUNTS_RECEIVABLE,
                Amount::round((float) $invoice->grand_total),
                description: $invoice->invoice_no,
                partyType:   PostingRole::PARTY_CUSTOMER,
                partyId:     (int) $invoice->customer_id,
                partyName:   $invoice->customer?->customer_name,
                sourceType:  PostingRole::SOURCE_INVOICE,
                sourceId:    (int) $invoice->id,
            ),
            JournalLineRequest::credit(
                PostingRole::SALES_REVENUE,
                $revenue,
                description: 'Sales, net of tax, SSCL and trade discount',
            ),
        ];

        if ($tax > 0) {
            $lines[] = JournalLineRequest::credit(
                PostingRole::OUTPUT_TAX_PAYABLE,
                $tax,
                description: 'Output tax collected',
            );
        }

        if ($sscl > 0) {
            $lines[] = JournalLineRequest::credit(
                PostingRole::SSCL_PAYABLE,
                $sscl,
                description: 'SSCL inside the selling price',
            );
        }

        return new JournalRequest(
            sourceType:   PostingRole::SOURCE_INVOICE,
            sourceId:     (int) $invoice->id,
            journalDate:  $invoice->invoice_date->toDateTimeImmutable(),
            lines:        $lines,
            journalType:  JournalRequest::TYPE_AUTO,
            companyId:    $invoice->company_id === null ? null : (int) $invoice->company_id,
            locationId:   self::locationOf($invoice),
            narration:    $invoice->invoice_no . ' - invoice issued',
        );
    }

    /**
     * The location the goods were sold from: the DO's. An invoice has no
     * location of its own, and a direct-SO (advance) invoice has no DO and no
     * location at all, so it reports under the company only.
     *
     * Without it a location-filtered P&L shows that location's purchases and
     * stock (a GRN carries its location) but none of its sales.
     */
    public static function locationOf(Invoice $invoice): ?int
    {
        $locationId = $invoice->deliveryOrder?->location_id;

        return $locationId === null ? null : (int) $locationId;
    }
}
