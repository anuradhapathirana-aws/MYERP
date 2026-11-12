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

        // Each line stored what its tax code made claimable (recoverable_tax);
        // everything else on the line -- the goods, and any SSCL or VAT that
        // cannot be claimed -- is what the purchase cost. Lines saved before
        // tax codes were backfilled with the old gross x % figure, so one rule
        // covers every GRN.
        $tax = round((float) $grn->items->sum(fn ($item) => (float) $item->recoverable_tax), 2);
        $net = round((float) $grn->total_amount - $tax, 2);

        $lines = [
            JournalLineRequest::debit(
                PostingRole::PURCHASES,
                $net,
                description: 'Goods received, net of claimable tax',
            ),
        ];

        // Claimable tax paid to a supplier is an asset, not a cost. Only posted
        // when there is some: a line with no tax code, or one whose tax cannot
        // be claimed, contributes nothing here, and JournalLineRequest rejects
        // a zero line anyway.
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
