<?php

declare(strict_types=1);

namespace Modules\Inventory\Posting;

use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use App\Support\Journal\PostingRole;
use App\Support\Money\Amount;
use Modules\Inventory\Models\CustomerReturn;
use Modules\Inventory\Models\InvoiceItem;

/**
 * Turns a confirmed customer return into a balanced journal -- the invoice's
 * posting run backwards for the returned quantity.
 *
 *   Dr  Sales Returns          the goods, net of VAT and SSCL
 *   Dr  Output Tax Payable     the VAT the invoice collected on them
 *   Dr  SSCL Payable           the SSCL inside their price
 *   Cr  Accounts Receivable    the part that reduced what the invoice still
 *                              owes (applied_to_invoice), source = the invoice
 *                              so per-invoice ageing clears with it
 *   Cr  Customer Advances      the rest -- the invoice was already paid, so the
 *                              customer holds a credit note instead
 *
 * Sales Returns rather than a debit to Sales, so the P&L shows how much came
 * back. VAT and SSCL are reversed at the rates stamped on each invoice line, so
 * the return undoes exactly what was charged.
 */
final class CustomerReturnJournal
{
    public static function build(CustomerReturn $return): JournalRequest
    {
        $return->loadMissing(['items', 'customer', 'invoice:id,invoice_no,company_id']);

        $invoiceItems = InvoiceItem::whereKey($return->items->pluck('invoice_item_id')->filter()->all())
            ->get(['id', 'vat_inclusive_pct', 'sscl_pct', 'vat_on_net'])
            ->keyBy('id');

        $tax  = 0.0;
        $sscl = 0.0;

        foreach ($return->items as $item) {
            $source = $invoiceItems->get($item->invoice_item_id);

            $split = InvoiceLineSplit::of(
                (float) $item->line_total,
                (float) $item->tax,
                (float) $item->discount,
                $source?->vat_inclusive_pct === null ? null : (float) $source->vat_inclusive_pct,
                $source?->sscl_pct === null ? null : (float) $source->sscl_pct,
                (bool) ($source?->vat_on_net ?? false),
            );

            $tax  += $split['vat'];
            $sscl += $split['sscl'];
        }

        $total    = Amount::round((float) $return->total_amount);
        $tax      = Amount::round($tax);
        $sscl     = Amount::round($sscl);
        $net      = Amount::round($total - $tax - $sscl);
        $applied  = Amount::round((float) $return->applied_to_invoice);
        $excess   = Amount::round($total - $applied);

        $lines = [
            JournalLineRequest::debit(
                PostingRole::SALES_RETURNS,
                $net,
                description: 'Goods returned, net of tax',
            ),
        ];

        if ($tax > 0) {
            $lines[] = JournalLineRequest::debit(
                PostingRole::OUTPUT_TAX_PAYABLE,
                $tax,
                description: 'Output tax on returned goods',
            );
        }

        if ($sscl > 0) {
            $lines[] = JournalLineRequest::debit(
                PostingRole::SSCL_PAYABLE,
                $sscl,
                description: 'SSCL on returned goods',
            );
        }

        if ($applied > 0) {
            $lines[] = JournalLineRequest::credit(
                PostingRole::ACCOUNTS_RECEIVABLE,
                $applied,
                description: $return->invoice?->invoice_no,
                partyType:   PostingRole::PARTY_CUSTOMER,
                partyId:     (int) $return->customer_id,
                partyName:   $return->customer?->customer_name,
                sourceType:  PostingRole::SOURCE_INVOICE,
                sourceId:    (int) $return->invoice_id,
            );
        }

        if ($excess > 0) {
            $lines[] = JournalLineRequest::credit(
                PostingRole::CUSTOMER_ADVANCE,
                $excess,
                description: 'Credit note for returned goods already paid for',
                partyType:   PostingRole::PARTY_CUSTOMER,
                partyId:     (int) $return->customer_id,
                partyName:   $return->customer?->customer_name,
            );
        }

        return new JournalRequest(
            sourceType:   PostingRole::SOURCE_CUSTOMER_RETURN,
            sourceId:     (int) $return->id,
            journalDate:  $return->return_date->toDateTimeImmutable(),
            lines:        $lines,
            journalType:  JournalRequest::TYPE_AUTO,
            companyId:    $return->invoice?->company_id === null ? null : (int) $return->invoice->company_id,
            // The location the goods came back to, so a location P&L nets the
            // return against that location's sales.
            locationId:   $return->location_id === null ? null : (int) $return->location_id,
            narration:    $return->return_no . ' - goods returned'
                . ($return->invoice?->invoice_no ? ' against ' . $return->invoice->invoice_no : ''),
        );
    }
}
