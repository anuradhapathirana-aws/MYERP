<?php

declare(strict_types=1);

namespace Modules\Inventory\Posting;

use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use App\Support\Journal\PostingRole;
use Modules\Inventory\Models\SupplierReturn;

/**
 * Turns a confirmed supplier return into a balanced journal -- the GRN's posting
 * run backwards for the returned quantity.
 *
 *   Dr  Accounts Payable        the part that reduced what the GRN still owes
 *                               (applied_to_grn), source = the GRN so per-GRN
 *                               ageing clears with it
 *   Dr  Supplier Advances       the rest -- the GRN was already paid, so the
 *                               supplier now owes us a credit note, which a
 *                               later supplier payment sets off
 *   Cr  Purchase Returns        the goods, net of tax
 *   Cr  Input Tax Recoverable   the tax the GRN claimed on them
 *
 * Purchase Returns rather than a credit to Purchases, so the P&L shows how much
 * went back. Tax is recomputed with the GRN's own formula (on gross, not net of
 * discount -- see GoodsReceivedNoteJournal), so the return undoes exactly what
 * the GRN claimed.
 */
final class SupplierReturnJournal
{
    public static function build(SupplierReturn $return): JournalRequest
    {
        $return->loadMissing(['items', 'supplier', 'grn:id,grn_no,location_id']);

        $tax = 0.0;

        foreach ($return->items as $item) {
            $gross = (float) $item->quantity * (float) $item->unit_price;
            $tax  += $gross * ((float) $item->tax / 100);
        }

        $total   = round((float) $return->total_amount, 2);
        $tax     = round($tax, 2);
        $net     = round($total - $tax, 2);
        $applied = round((float) $return->applied_to_grn, 2);
        $excess  = round($total - $applied, 2);

        $lines = [];

        if ($applied > 0) {
            $lines[] = JournalLineRequest::debit(
                PostingRole::ACCOUNTS_PAYABLE,
                $applied,
                description: $return->grn?->grn_no,
                partyType:   PostingRole::PARTY_SUPPLIER,
                partyId:     (int) $return->supplier_id,
                partyName:   $return->supplier?->supplier_name,
                sourceType:  PostingRole::SOURCE_GRN,
                sourceId:    (int) $return->grn_id,
            );
        }

        if ($excess > 0) {
            $lines[] = JournalLineRequest::debit(
                PostingRole::SUPPLIER_ADVANCE,
                $excess,
                description: 'Credit note for returned goods already paid for',
                partyType:   PostingRole::PARTY_SUPPLIER,
                partyId:     (int) $return->supplier_id,
                partyName:   $return->supplier?->supplier_name,
            );
        }

        $lines[] = JournalLineRequest::credit(
            PostingRole::PURCHASE_RETURNS,
            $net,
            description: 'Goods returned, net of tax',
        );

        if ($tax > 0) {
            $lines[] = JournalLineRequest::credit(
                PostingRole::INPUT_TAX_RECOVERABLE,
                $tax,
                description: 'Input tax on returned goods',
            );
        }

        return new JournalRequest(
            sourceType:   PostingRole::SOURCE_SUPPLIER_RETURN,
            sourceId:     (int) $return->id,
            journalDate:  $return->return_date->toDateTimeImmutable(),
            lines:        $lines,
            journalType:  JournalRequest::TYPE_AUTO,
            locationId:   $return->grn?->location_id === null ? null : (int) $return->grn->location_id,
            narration:    $return->return_no . ' - goods returned to supplier'
                . ($return->grn?->grn_no ? ' against ' . $return->grn->grn_no : ''),
        );
    }
}
