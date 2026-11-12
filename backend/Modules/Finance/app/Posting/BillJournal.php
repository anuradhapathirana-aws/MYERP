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
 *   Dr  each line's account        line total less its claimable tax -- the
 *                                  charge itself plus any tax that cannot be
 *                                  claimed (explicit account, chosen on screen)
 *   Dr  Input Tax Recoverable      the claimable tax of every line (role)
 *   Cr  Accounts Payable           the bill total, party = supplier (role --
 *                                  resolved per supplier group / per-party mode
 *                                  like every payable)
 *
 * A clearing agent's bill with a fee (40,000 + VAT 7,200), duty paid for us
 * (477,000) and the CusDec's import VAT (680,000) therefore posts
 *
 *   Dr Clearing & Transport In    40,000
 *   Dr Import Duty & Levies      477,000
 *   Dr Input Tax Recoverable     687,200
 *      Cr Agent                         1,204,200
 *
 * An Import VAT line that is fully claimable leaves nothing for its own
 * account, so it adds no line of its own. A bill without tax posts exactly as
 * before tax existed (line_total = amount, recoverable 0).
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

        $lines       = [];
        $recoverable = 0;

        foreach ($bill->lines as $line) {
            $cost = Bill::toCents($line->costAmount());

            if ($cost > 0) {
                $lines[] = JournalLineRequest::debit(
                    (int) $line->ledger_account_id,
                    $cost / 100,
                    description: $line->description ?? $bill->description ?? 'Supplier invoice ' . $bill->document_no,
                );
            }

            $recoverable += Bill::toCents($line->recoverable_tax);
        }

        if ($recoverable > 0) {
            $lines[] = JournalLineRequest::debit(
                PostingRole::INPUT_TAX_RECOVERABLE,
                $recoverable / 100,
                description: 'Claimable tax, supplier invoice ' . $bill->document_no,
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
