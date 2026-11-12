<?php

declare(strict_types=1);

namespace App\Support\Banking;

use DateTimeInterface;

/**
 * One cheque the bank returned, as Bank Reconciliation hands it to the
 * document that wrote it.
 *
 * The cheque is named the way the bank line of the ledger names it
 * (sourceType / sourceId). The bounce entry moves the money on
 * bankAccountId -- the account the statement belongs to, which is not always
 * the account the document first put the cheque in (a received cheque goes to
 * Cheques in Hand, then a Bank Deposit moves it to the bank).
 */
final class ChequeReturnRequest
{
    public function __construct(
        public readonly string            $sourceType,
        public readonly int               $sourceId,
        public readonly DateTimeInterface $returnDate,
        public readonly string            $reference,
        public readonly int               $bankAccountId,
    ) {}
}
