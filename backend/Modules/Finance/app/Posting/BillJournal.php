<?php

declare(strict_types=1);

namespace Modules\Finance\Posting;

use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use App\Support\Journal\PostingRole;
use Modules\Finance\Models\Bill;

/**
 * Turns a confirmed bill into a balanced journal.
 *
 *   Dr  each line's expense account          (explicit account, chosen on screen)
 *   Cr  Accounts Payable, party = supplier   (role -- resolved per supplier group /
 *                                             per-party mode like every payable)
 *
 * The payable line carries source = bill so Bill Payment can later clear
 * exactly this bill, the same way supplier payments clear GRNs today.
 *
 * Same shape as Inventory's GoodsReceivedNoteJournal, kept in Finance because
 * a bill is a Finance document.
 */
final class BillJournal
{
    public static function build(Bill $bill): JournalRequest
    {
        $bill->loadMissing(['lines', 'supplier']);

        $lines = [];

        foreach ($bill->lines as $line) {
            $lines[] = JournalLineRequest::debit(
                (int) $line->ledger_account_id,
                (float) $line->amount,
                description: $line->description ?? $bill->description ?? 'Supplier invoice ' . $bill->document_no,
            );
        }

        $lines[] = JournalLineRequest::credit(
            PostingRole::ACCOUNTS_PAYABLE,
            (float) $bill->total_amount,
            description: 'Supplier invoice ' . $bill->document_no,
            partyType:   PostingRole::PARTY_SUPPLIER,
            partyId:     (int) $bill->supplier_id,
            partyName:   $bill->supplier?->supplier_name,
            sourceType:  PostingRole::SOURCE_BILL,
            sourceId:    (int) $bill->id,
        );

        return new JournalRequest(
            sourceType:   PostingRole::SOURCE_BILL,
            sourceId:     (int) $bill->id,
            journalDate:  $bill->transaction_date->toDateTimeImmutable(),
            lines:        $lines,
            journalType:  JournalRequest::TYPE_AUTO,
            documentDate: $bill->document_date?->toDateTimeImmutable(),
            companyId:    (int) $bill->company_id,
            locationId:   $bill->location_id === null ? null : (int) $bill->location_id,
            narration:    $bill->bill_no . ' - ' . ($bill->supplier?->supplier_name ?? 'supplier') . ' bill '
                . $bill->document_no,
        );
    }
}
