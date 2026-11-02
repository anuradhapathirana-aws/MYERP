<?php

declare(strict_types=1);

namespace Modules\Finance\Posting;

use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use App\Support\Journal\PostingRole;
use Modules\Finance\Models\BillPayment;

/**
 * Turns a confirmed bill payment into a balanced journal.
 *
 *   Dr  Accounts Payable, one line per bill   (role, party = supplier,
 *                                              source = that bill)
 *   Cr  the Cash Book / Bank paid from        (explicit account, chosen on screen)
 *
 * The payable is cleared per bill, mirroring the credit BillJournal raised
 * with source = bill, so Aged Payables can age and settle each bill rather
 * than only the supplier as a whole. Same shape as Inventory's
 * SupplierPaymentJournal clearing GRNs.
 */
final class BillPaymentJournal
{
    public static function build(BillPayment $payment): JournalRequest
    {
        $payment->loadMissing(['allocations.bill', 'supplier']);

        $supplierName = $payment->supplier?->supplier_name;
        $lines        = [];

        foreach ($payment->allocations as $allocation) {
            $lines[] = JournalLineRequest::debit(
                PostingRole::ACCOUNTS_PAYABLE,
                (float) $allocation->amount,
                description: 'Payment of ' . ($allocation->bill?->bill_no ?? 'bill')
                    . ($allocation->bill?->document_no ? ' / ' . $allocation->bill->document_no : ''),
                partyType:   PostingRole::PARTY_SUPPLIER,
                partyId:     (int) $payment->supplier_id,
                partyName:   $supplierName,
                sourceType:  PostingRole::SOURCE_BILL,
                sourceId:    (int) $allocation->bill_id,
            );
        }

        $lines[] = JournalLineRequest::credit(
            (int) $payment->pay_from_account_id,
            (float) $payment->total_amount,
            description: self::moneyNote($payment),
        );

        return new JournalRequest(
            sourceType:   PostingRole::SOURCE_BILL_PAYMENT,
            sourceId:     (int) $payment->id,
            journalDate:  $payment->payment_date->toDateTimeImmutable(),
            lines:        $lines,
            journalType:  JournalRequest::TYPE_AUTO,
            companyId:    (int) $payment->company_id,
            narration:    $payment->payment_no . ' - payment to ' . ($supplierName ?? 'supplier'),
        );
    }

    private static function moneyNote(BillPayment $payment): string
    {
        // The snapshot, so a later rename of the mode never changes a posted note.
        $note = (string) $payment->payment_mode_name;

        if ($payment->cheque_no !== null) {
            $note .= ' ' . $payment->cheque_no . ' dated ' . $payment->cheque_date?->format('d-m-Y');
        }

        return $payment->reference_no ? $note . ' (ref ' . $payment->reference_no . ')' : $note;
    }
}
