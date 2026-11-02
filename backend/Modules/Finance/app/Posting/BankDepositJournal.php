<?php

declare(strict_types=1);

namespace Modules\Finance\Posting;

use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use App\Support\Journal\PostingRole;
use Modules\Finance\Models\BankDeposit;
use Modules\Finance\Models\BankDepositLine;

/**
 * Turns a confirmed bank deposit into a balanced journal.
 *
 *   Dr  the Bank account deposited into        (the slip total)
 *   Cr  the Cash Book the notes came from      (one line for the cash)
 *   Cr  Cheques in Hand, one line per cheque   (so its ledger lists each
 *                                               cheque that left it)
 *
 * No party on any line: the customer was settled by the receipt. This only
 * moves money between the company's own accounts.
 */
final class BankDepositJournal
{
    public static function build(BankDeposit $deposit): JournalRequest
    {
        $deposit->loadMissing(['lines', 'bankAccount']);

        $lines = [
            JournalLineRequest::debit(
                (int) $deposit->bank_account_id,
                (float) $deposit->total_amount,
                description: 'Deposit ' . $deposit->deposit_no . ($deposit->slip_no ? ' (slip ' . $deposit->slip_no . ')' : ''),
            ),
        ];

        foreach ($deposit->lines as $line) {
            $lines[] = JournalLineRequest::credit(
                (int) $line->from_account_id,
                (float) $line->amount,
                description: self::lineNote($line),
            );
        }

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
