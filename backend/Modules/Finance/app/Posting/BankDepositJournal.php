<?php

declare(strict_types=1);

namespace Modules\Finance\Posting;

use App\Enums\PaymentModeType;
use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use App\Support\Journal\PostingRole;
use Modules\Finance\Models\BankDeposit;
use Modules\Finance\Models\BankDepositLine;

/**
 * Turns a confirmed bank deposit into a balanced journal.
 *
 *   Dr  the Bank account, one line per cheque  (cheque no + date on the line,
 *                                               source = the receipt's
 *                                               settlement row)
 *   Dr  the Bank account, one line for cash    (when the slip carries cash)
 *   Cr  the Cash Book the notes came from      (one line for the cash)
 *   Cr  Cheques in Hand, one line per cheque   (so its ledger lists each
 *                                               cheque that left it)
 *
 * The bank side is split per cheque, not posted as one slip total, so Bank
 * Reconciliation can realise each cheque on the day the bank clears it and
 * return the one that bounces. Deposits confirmed before this change keep
 * their single slip line: a posted journal is never rewritten.
 *
 * No party on any line: the customer was settled by the receipt. This only
 * moves money between the company's own accounts.
 */
final class BankDepositJournal
{
    public static function build(BankDeposit $deposit): JournalRequest
    {
        $deposit->loadMissing(['lines', 'bankAccount']);

        $bankId = (int) $deposit->bank_account_id;
        $slip   = 'Deposit ' . $deposit->deposit_no . ($deposit->slip_no ? ' (slip ' . $deposit->slip_no . ')' : '');
        $debits = [];
        $credits = [];

        foreach ($deposit->lines as $line) {
            if ($line->isCheque()) {
                $debits[] = JournalLineRequest::debit(
                    $bankId,
                    (float) $line->amount,
                    description: mb_substr($slip . ' - ' . self::lineNote($line), 0, 255),
                    sourceType:  $line->source_type,
                    sourceId:    $line->source_id === null ? null : (int) $line->source_id,
                )->withInstrument(
                    PaymentModeType::Cheque->value,
                    $line->cheque_no,
                    $line->cheque_date?->toDateString(),
                );
            } else {
                $debits[] = JournalLineRequest::debit(
                    $bankId,
                    (float) $line->amount,
                    description: $slip . ' - cash',
                )->withInstrument(PaymentModeType::Cash->value, $deposit->slip_no);
            }

            $credits[] = JournalLineRequest::credit(
                (int) $line->from_account_id,
                (float) $line->amount,
                description: self::lineNote($line),
            );
        }

        $lines = [...$debits, ...$credits];

        return new JournalRequest(
            sourceType:   PostingRole::SOURCE_BANK_DEPOSIT,
            sourceId:     (int) $deposit->id,
            journalDate:  $deposit->deposit_date->toDateTimeImmutable(),
            lines:        $lines,
            journalType:  JournalRequest::TYPE_AUTO,
            companyId:    (int) $deposit->company_id,
            narration:    $deposit->deposit_no . ' - deposit to ' . ($deposit->bankAccount?->ledger_account_name ?? 'bank'),
        );
    }

    private static function lineNote(BankDepositLine $line): string
    {
        if (! $line->isCheque()) {
            return 'Cash deposited';
        }

        return 'Cheque ' . $line->cheque_no . ' dated ' . $line->cheque_date?->format('d-m-Y')
            . ' - ' . ($line->customer_name ?? 'customer') . ' (' . $line->receipt_no . ')';
    }
}
