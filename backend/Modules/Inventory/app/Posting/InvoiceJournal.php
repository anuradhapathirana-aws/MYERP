<?php

declare(strict_types=1);

namespace Modules\Inventory\Posting;

use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use App\Support\Journal\PostingRole;
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
 */
final class InvoiceJournal
{
    public static function build(Invoice $invoice): JournalRequest
    {
        $invoice->loadMissing(['items', 'customer']);

        $tax     = 0.0;
        $revenue = 0.0;

        foreach ($invoice->items as $item) {
            // Mirrors InvoiceService::recalculateLineTotal exactly:
            //   gross      = quantity x unit_price
            //   line_total = gross - gross x discount% + gross x tax%
            $gross   = (float) $item->quantity * (float) $item->unit_price;
            $lineTax = $gross * ((float) $item->tax / 100);

            $tax     += $lineTax;
            $revenue += (float) $item->line_total - $lineTax;
        }

        // Transport is part of what the customer is billed, and carries no tax
        // of its own in this schema, so it is revenue.
        $revenue += (float) $invoice->transport_charge;

        $tax     = round($tax, 2);
        $revenue = round($revenue, 2);

        $lines = [
            JournalLineRequest::debit(
                PostingRole::ACCOUNTS_RECEIVABLE,
                round((float) $invoice->grand_total, 2),
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
                description: 'Sales, net of tax and trade discount',
            ),
        ];

        if ($tax > 0) {
            $lines[] = JournalLineRequest::credit(
                PostingRole::OUTPUT_TAX_PAYABLE,
                $tax,
                description: 'Output tax collected',
            );
        }

        return new JournalRequest(
            sourceType:   PostingRole::SOURCE_INVOICE,
            sourceId:     (int) $invoice->id,
            journalDate:  $invoice->invoice_date->toDateTimeImmutable(),
            lines:        $lines,
            journalType:  JournalRequest::TYPE_AUTO,
            companyId:    $invoice->company_id === null ? null : (int) $invoice->company_id,
            narration:    $invoice->invoice_no . ' - invoice issued',
        );
    }
}
