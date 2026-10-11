<?php

declare(strict_types=1);

namespace Modules\Inventory\Posting;

use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use App\Support\Journal\PostingRole;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Enums\SetoffType;
use Modules\Inventory\Models\SupplierCreditNote;
use Modules\Inventory\Models\SupplierPayment;

/**
 * Turns a confirmed supplier payment into a balanced journal.
 *
 * Lives in Inventory because the knowledge is Inventory's: what an allocation
 * means, how a set-off is funded, which columns hold what. It names ROLES
 * rather than account ids, so nothing here reads a fin_ table and the two
 * modules stay independent. Finance resolves the roles when it posts.
 *
 * ── Why the amounts balance by construction ──────────────────────────────────
 * finalizeAmounts() already guarantees gross = setoff + net for an ordinary
 * payment, and confirm() mints a credit note for any excess beyond that. So:
 *
 *   Dr  (gross + discount) + credit notes minted
 *   Cr  net + setoff + discount
 *
 * With no overfunding the two reduce to gross = setoff + net. With overfunding
 * the minted note is exactly setoff + net - gross, which closes the gap. The
 * posting service re-checks anyway, because a balance assertion that lives
 * only in a comment is not an assertion.
 *
 * ── Payables are posted per allocation, not as one lump ──────────────────────
 * Each allocation becomes its own Accounts Payable line carrying
 * source = (grn, id). That is what lets Aged Payables age per GRN rather than
 * only per supplier, and it mirrors computeOutstanding(), which clears a GRN
 * by payment_amount + discount.
 */
final class SupplierPaymentJournal
{
    /**
     * @throws ValidationException when a settlement line does not say which
     *         cash or bank account the money left.
     */
    public static function build(SupplierPayment $payment): JournalRequest
    {
        $payment->loadMissing(['allocations', 'setoffs', 'settlements', 'supplier']);

        $lines = [];

        // ── Dr: the payable being cleared, one line per GRN ──────────────
        foreach ($payment->allocations as $allocation) {
            $cleared = round(
                (float) $allocation->payment_amount + (float) $allocation->discount,
                2,
            );

            if ($cleared <= 0) {
                continue;
            }

            $lines[] = JournalLineRequest::debit(
                PostingRole::ACCOUNTS_PAYABLE,
                $cleared,
                description: $allocation->reference_no ?: null,
                partyType:   PostingRole::PARTY_SUPPLIER,
                partyId:     (int) $payment->supplier_id,
                partyName:   $payment->supplier?->supplier_name,
                sourceType:  (string) $allocation->reference_type,
                sourceId:    (int) $allocation->reference_id,
            );
        }

        // ── Dr: prepayments and overpayments this payment created ────────
        //
        // An advance, or cash paid beyond what was owed, is money the supplier
        // now holds on our behalf -- an asset, not an expense. confirm() has
        // already minted the credit note by the time this runs, so the note is
        // the single source of truth for the amount rather than a second
        // calculation that could disagree with it.
        $minted = round((float) SupplierCreditNote::where('source_payment_id', $payment->id)->sum('amount'), 2);

        if ($minted > 0) {
            $lines[] = JournalLineRequest::debit(
                PostingRole::SUPPLIER_ADVANCE,
                $minted,
                description: 'Advance / overpayment held by supplier',
                partyType:   PostingRole::PARTY_SUPPLIER,
                partyId:     (int) $payment->supplier_id,
                partyName:   $payment->supplier?->supplier_name,
            );
        }

        // ── Cr: the money, from the account the user chose ───────────────
        foreach ($payment->settlements as $settlement) {
            $amount = round((float) $settlement->amount, 2);

            if ($amount <= 0) {
                continue;
            }

            if (empty($settlement->ledger_account_id)) {
                throw ValidationException::withMessages([
                    'settlements' => 'Payment ' . $payment->payment_no . ' has a '
                        . $settlement->payment_mode_name . ' line with no Pay From account. '
                        . 'Choose the cash or bank account the money left, then confirm again.',
                ]);
            }

            $lines[] = JournalLineRequest::credit(
                (int) $settlement->ledger_account_id,
                $amount,
                description: self::settlementNote($settlement),
            );
        }

        // ── Cr: set-offs, by what they actually consume ──────────────────
        foreach (self::setoffTotals($payment) as $role => $amount) {
            $lines[] = JournalLineRequest::credit(
                $role,
                $amount,
                description: 'Set-off',
                partyType:   PostingRole::PARTY_SUPPLIER,
                partyId:     (int) $payment->supplier_id,
                partyName:   $payment->supplier?->supplier_name,
            );
        }

        // ── Cr: discount taken ───────────────────────────────────────────
        //
        // A settlement discount, not a trade discount. The codebase treats it
        // as reducing the payable exactly like cash, so it has to appear as its
        // own line or the payable would not clear.
        $discount = round((float) $payment->discount_amount, 2);

        if ($discount > 0) {
            $lines[] = JournalLineRequest::credit(
                PostingRole::DISCOUNT_RECEIVED,
                $discount,
                description: 'Settlement discount received',
            );
        }

        return new JournalRequest(
            sourceType:   PostingRole::SOURCE_SUPPLIER_PAYMENT,
            sourceId:     (int) $payment->id,
            journalDate:  $payment->payment_date->toDateTimeImmutable(),
            lines:        $lines,
            journalType:  JournalRequest::TYPE_AUTO,
            documentDate: $payment->transaction_date?->toDateTimeImmutable(),
            // Company is left for Finance to derive from the chosen bank
            // account, which already carries one. That is why this header has
            // no company_id column and why Inventory never has to look one up.
            narration:    self::narration($payment),
        );
    }

    /**
     * Set-off amounts grouped by the account they consume.
     *
     * Three types, two meanings:
     *
     *   advance / over_payment  -- spending a prepayment we already made, so it
     *                              releases the Supplier Advances asset.
     *   customer_return         -- goods sent back to the supplier. Under the
     *                              periodic stock method the original cost went
     *                              to Purchases, so the return reverses it
     *                              there. Were stock ever moved to perpetual
     *                              valuation this would credit Inventory
     *                              instead.
     *
     * @return array<string, float> role => amount
     */
    private static function setoffTotals(SupplierPayment $payment): array
    {
        $totals = [];

        foreach ($payment->setoffs as $setoff) {
            $amount = round((float) $setoff->amount, 2);

            if ($amount <= 0) {
                continue;
            }

            $role = $setoff->setoff_type === SetoffType::CustomerReturn
                ? PostingRole::PURCHASES
                : PostingRole::SUPPLIER_ADVANCE;

            $totals[$role] = round(($totals[$role] ?? 0) + $amount, 2);
        }

        return $totals;
    }

    /** Cheque or transfer reference on the line, so the GL reads like the voucher. */
    private static function settlementNote(object $settlement): ?string
    {
        $parts = array_filter([
            $settlement->payment_mode_name ?: null,
            $settlement->reference_no ?: null,
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    private static function narration(SupplierPayment $payment): string
    {
        $base = $payment->payment_no;

        if ($payment->is_advance) {
            return $base . ' - advance to supplier';
        }

        $count = $payment->allocations->count();

        return $base . ' - ' . $count . ' ' . ($count === 1 ? 'GRN' : 'GRNs') . ' settled';
    }
}
