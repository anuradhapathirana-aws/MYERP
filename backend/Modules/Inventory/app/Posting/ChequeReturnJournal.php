<?php

declare(strict_types=1);

namespace Modules\Inventory\Posting;

use App\Enums\PaymentModeType;
use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use App\Support\Journal\PostingRole;
use Modules\Inventory\Models\ChequeReturn;
use Modules\Inventory\Models\ChequeReturnAllocation;

/**
 * The bounce entry of a returned cheque: the receipt or payment journal
 * undone for that one cheque, on the bank account the statement belongs to.
 *
 *   Received cheque (customer receipt)        Issued cheque (supplier payment)
 *     Dr Receivables, per invoice reopened      Dr Bank
 *     Dr Customer Advances, per credit note       Cr Payables, per GRN reopened
 *       Cr Bank                                   Cr Supplier Advances, per credit note
 *
 *   plus, per settlement discount taken back:
 *     Dr Receivables (invoice)                  Dr Discount Received
 *       Cr Discount Allowed                       Cr Payables (GRN)
 *
 * An invoice / GRN the cheque's advance paid on another document is reopened
 * on Receivables / Payables like any other: the advance was spent there, so
 * Customer / Supplier Advances is already back to nothing for it.
 *
 * The bank line names the same settlement and cheque as the line it cancels,
 * so the reconciliation can clear the pair together. Posted as an adjustment,
 * not AUTO: the bank has already done it, so review mode must not hold it.
 */
final class ChequeReturnJournal
{
    /**
     * @param list<string> $labels a document number per allocation, in allocation order
     */
    public static function build(ChequeReturn $return, ?string $partyName, array $labels): JournalRequest
    {
        $return->loadMissing('allocations');

        $received = $return->document_type === ChequeReturn::DOCUMENT_CUSTOMER_RECEIPT;
        $party    = $received ? PostingRole::PARTY_CUSTOMER : PostingRole::PARTY_SUPPLIER;
        $note     = 'Cheque ' . ($return->cheque_no ?? '') . ' returned';
        $lines    = [];

        foreach ($return->allocations->sortBy('id')->values() as $i => $allocation) {
            $amount = round((float) $allocation->amount, 2);

            if ($amount <= 0) {
                continue;
            }

            $label       = $labels[$i] ?? null;
            $description = $label === null || $label === '' ? $note : $note . ' - ' . $label;

            $partyLine = static function (string $role, ?string $sourceType, bool $debit, string $text) use ($amount, $party, $return, $partyName, $allocation): JournalLineRequest {
                $sourceId = $sourceType === null ? null : $allocation->reference_id;

                return $debit
                    ? JournalLineRequest::debit($role, $amount, description: $text, partyType: $party, partyId: $return->party_id, partyName: $partyName, sourceType: $sourceType, sourceId: $sourceId)
                    : JournalLineRequest::credit($role, $amount, description: $text, partyType: $party, partyId: $return->party_id, partyName: $partyName, sourceType: $sourceType, sourceId: $sourceId);
            };

            switch ($allocation->reference_type) {
                case ChequeReturnAllocation::INVOICE_DISCOUNT:
                    $text    = 'Discount taken back, ' . strtolower($note) . ($label ? ' - ' . $label : '');
                    $lines[] = $partyLine(PostingRole::ACCOUNTS_RECEIVABLE, PostingRole::SOURCE_INVOICE, true, $text);
                    $lines[] = JournalLineRequest::credit(PostingRole::DISCOUNT_ALLOWED, $amount, description: $text);
                    break;

                case ChequeReturnAllocation::GRN_DISCOUNT:
                    $text    = 'Discount given back, ' . strtolower($note) . ($label ? ' - ' . $label : '');
                    $lines[] = JournalLineRequest::debit(PostingRole::DISCOUNT_RECEIVED, $amount, description: $text);
                    $lines[] = $partyLine(PostingRole::ACCOUNTS_PAYABLE, PostingRole::SOURCE_GRN, false, $text);
                    break;

                default:
                    [$role, $sourceType] = match ($allocation->reference_type) {
                        ChequeReturnAllocation::INVOICE              => [PostingRole::ACCOUNTS_RECEIVABLE, PostingRole::SOURCE_INVOICE],
                        ChequeReturnAllocation::GRN                  => [PostingRole::ACCOUNTS_PAYABLE, PostingRole::SOURCE_GRN],
                        ChequeReturnAllocation::CUSTOMER_CREDIT_NOTE => [PostingRole::CUSTOMER_ADVANCE, null],
                        ChequeReturnAllocation::SUPPLIER_CREDIT_NOTE => [PostingRole::SUPPLIER_ADVANCE, null],
                    };

                    $lines[] = $partyLine($role, $sourceType, $received, $description);
            }
        }

        $amount      = round((float) $return->amount, 2);
        $bankNote    = $note . ' (' . $return->document_no . ')';
        $lines[]     = ($received
            ? JournalLineRequest::credit((int) $return->bank_account_id, $amount, description: $bankNote, sourceType: $return->settlement_type, sourceId: $return->settlement_id)
            : JournalLineRequest::debit((int) $return->bank_account_id, $amount, description: $bankNote, sourceType: $return->settlement_type, sourceId: $return->settlement_id)
        )->withInstrument(PaymentModeType::Cheque->value, $return->cheque_no, $return->cheque_date?->toDateString());

        return new JournalRequest(
            sourceType:   PostingRole::SOURCE_CHEQUE_RETURN,
            sourceId:     (int) $return->id,
            journalDate:  $return->return_date->toDateTimeImmutable(),
            lines:        $lines,
            journalType:  JournalRequest::TYPE_ADJUSTMENT,
            narration:    $note . ' - ' . $return->document_no . ' (' . $return->reference . ')',
        );
    }
}
