<?php

declare(strict_types=1);

namespace Modules\Inventory\Posting;

use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use App\Support\Journal\PostingRole;
use Modules\Inventory\Models\GoodsReceivedNote;

/**
 * Turns a confirmed GRN into a balanced journal.
 *
 * This is the half of the bridge that makes a supplier payment mean anything.
 * A payment DEBITS Accounts Payable; without the GRN crediting it first, the
 * account would hold debits with no matching credit and read as a negative
 * liability. The two have to post together or neither is worth posting.
 *
 * ── Why Purchases and not Inventory ─────────────────────────────────────────
 * The periodic stock method, decided deliberately. Perpetual would debit
 * Inventory here and require every sale to post Cost of Sales -- which needs a
 * reliable cost on each invoice line, and the only path to one runs through two
 * nullable links and covers piece-tracked products alone. A direct invoice
 * would post zero cost and silently inflate profit.
 *
 * Moving to perpetual later is a mapping change plus a cost source: this line
 * repoints from PURCHASES to INVENTORY, and the invoice gains a Cost of Sales
 * pair. Nothing here needs restructuring.
 *
 * ── Why the credit side is a role, not a fixed account ──────────────────────
 * Today the GRN IS the payable document, so it credits Accounts Payable. When
 * Bill Entry ships, the GRN will credit GRNI instead and the Bill will move
 * GRNI to Payables -- the three-way match. Because this names a role, that
 * switch is a one-row mapping update on a chosen cutover date rather than a
 * code change.
 */
final class GoodsReceivedNoteJournal
{
    public static function build(GoodsReceivedNote $grn): JournalRequest
    {
        $grn->loadMissing(['items', 'supplier']);

        $tax = 0.0;
        $net = 0.0;

        foreach ($grn->items as $item) {
            // Mirrors GoodsReceivedNoteService exactly:
            //   gross     = quantity_received x unit_price
            //   taxAmt    = gross x tax%            (on gross, NOT net of discount)
            //   lineTotal = gross - discount + taxAmt
            // Recomputing rather than storing a tax column keeps one formula in
            // the system; if that formula ever changes, this follows it.
            $gross  = (float) $item->quantity_received * (float) $item->unit_price;
            $lineTax = $gross * ((float) $item->tax / 100);

            $tax += $lineTax;
            $net += (float) $item->line_total - $lineTax;
        }

        $tax = round($tax, 2);
        $net = round($net, 2);

        $lines = [
            JournalLineRequest::debit(
                PostingRole::PURCHASES,
                $net,
                description: 'Goods received, net of tax',
            ),
        ];

        // Tax paid to a supplier is reclaimable, not a cost. Only posted when
        // there is some: a non-registered client's lines carry 0% and would
        // otherwise get a pointless zero line, which JournalLineRequest
        // rejects anyway.
        if ($tax > 0) {
            $lines[] = JournalLineRequest::debit(
                PostingRole::INPUT_TAX_RECOVERABLE,
                $tax,
                description: 'Input tax on purchases',
            );
        }

        $lines[] = JournalLineRequest::credit(
            PostingRole::ACCOUNTS_PAYABLE,
            round((float) $grn->total_amount, 2),
            description: $grn->reference_no ?: null,
            partyType:   PostingRole::PARTY_SUPPLIER,
            partyId:     (int) $grn->supplier_id,
            partyName:   $grn->supplier?->supplier_name,
            sourceType:  PostingRole::SOURCE_GRN,
            sourceId:    (int) $grn->id,
        );

        return new JournalRequest(
            sourceType:   PostingRole::SOURCE_GRN,
            sourceId:     (int) $grn->id,
            journalDate:  $grn->grn_date->toDateTimeImmutable(),
            lines:        $lines,
            journalType:  JournalRequest::TYPE_AUTO,
            documentDate: $grn->transaction_date?->toDateTimeImmutable(),
            locationId:   $grn->location_id === null ? null : (int) $grn->location_id,
            narration:    $grn->grn_no . ' - goods received',
        );
    }
}
