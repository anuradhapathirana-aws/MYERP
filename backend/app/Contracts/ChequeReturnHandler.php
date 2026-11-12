<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Support\Banking\ChequeReturnRequest;

/**
 * Bouncing one cheque: a received cheque the bank returned unpaid, or an
 * issued cheque that came back (stopped, refused).
 *
 * Bank Reconciliation (Finance) decides THAT a cheque came back; the document
 * that wrote the cheque decides what that means -- which invoice or GRN
 * reopens, which party owes again. A cheque is named the way the bank line of
 * the ledger names it: source_type / source_id, e.g.
 * ('customer_receipt_settlement', 812). That keeps Finance out of inv_ tables
 * and Inventory out of fin_ tables.
 *
 * Bound to ChequeReturnRouter, which hands each cheque to the tagged
 * ChequeReturnSource of its document; a cheque no source answers for is
 * refused with a reason the screen shows.
 */
interface ChequeReturnHandler
{
    /**
     * Why each cheque cannot be returned, or null when it can.
     *
     * @param  list<array{source_type:?string, source_id:?int}> $cheques
     * @return array<string, ?string> keyed "{source_type}:{source_id}"
     */
    public function blockedReasons(array $cheques): array;

    /**
     * Post the bounce: put the money back on the party and reopen what the
     * cheque had settled. Runs inside the caller's transaction.
     *
     * @return string|null the journal number of the bounce entry
     */
    public function returnCheque(ChequeReturnRequest $request): ?string;

    /** The key blockedReasons() uses. */
    public static function key(?string $sourceType, ?int $sourceId): string;
}
