<?php

declare(strict_types=1);

namespace Modules\Inventory\Posting;

use App\Contracts\JournalPoster;
use App\Enums\PaymentModeType;
use App\Models\PaymentMode;
use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use App\Support\Journal\PostingRole;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Enums\CustomerSetoffType;
use Modules\Inventory\Models\CustomerCreditNote;
use Modules\Inventory\Models\CustomerReceipt;

/**
 * Turns a confirmed customer receipt into a balanced journal.
 *
 * The mirror of SupplierPaymentJournal, with every side flipped: money comes
 * in rather than out, a receivable clears rather than a payable, and a discount
 * is given rather than taken.
 *
 * ── The direction that is easy to get wrong ──────────────────────────────────
 * Customer Advances is a LIABILITY -- money we hold that is not yet ours. So a
 * customer overpaying CREDITS it (we now owe them), and a later set-off
 * DEBITS it (we no longer do). That is the opposite of Supplier Advances,
 * which is an asset: a prepayment we made is debited when created and credited
 * when consumed. Same word, opposite sign, because one is money we hold and
 * the other is money someone holds for us.
 *
 * ── Receivables are posted per invoice ──────────────────────────────────────
 * One line per allocation, carrying source = (invoice, id), so Aged
 * Receivables can age per invoice rather than only per customer. The amount is
 * receipt_amount + discount, exactly as InvoiceBalanceService clears an invoice.
 */
final class CustomerReceiptJournal
{
    /**
     * @throws ValidationException when a settlement line does not say which
     *         cash or bank account the money arrived in.
     */
    public static function build(CustomerReceipt $receipt): JournalRequest
    {
        $receipt->loadMissing(['allocations', 'setoffs', 'settlements', 'customer']);

        $lines = [];

        // ── Dr: the money, into the account the user chose ───────────────
        $modeTypes = PaymentMode::typesById($receipt->settlements->pluck('payment_mode_id')->map(fn ($id): int => (int) $id)->all());

        foreach ($receipt->settlements as $settlement) {
            $amount = round((float) $settlement->amount, 2);

            if ($amount <= 0) {
                continue;
            }

            if (empty($settlement->ledger_account_id)) {
                throw ValidationException::withMessages([
                    'settlements' => 'Receipt ' . $receipt->receipt_no . ' has a '
                        . $settlement->payment_mode_name . ' line with no Deposit To account. '
                        . 'Choose the cash or bank account the money arrived in, then confirm again.',
                ]);
            }

            // The settlement row is named on the money line, with its cheque
            // or transfer details, so Bank Reconciliation can match and
            // return this one instrument.
            $type = $modeTypes[(int) $settlement->payment_mode_id]
                ?? PaymentModeType::fromLegacyCode($settlement->payment_mode_code);

            $lines[] = JournalLineRequest::debit(
                (int) $settlement->ledger_account_id,
                $amount,
                description: self::settlementNote($settlement),
                sourceType:  PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT,
                sourceId:    (int) $settlement->id,
            )->withInstrument(
                $type->value,
                $settlement->reference_no,
                $settlement->instrument_date?->toDateString(),
            );
        }

        // ── Dr: set-offs, by what they actually release ──────────────────
        foreach (self::setoffTotals($receipt) as $role => $amount) {
            $lines[] = JournalLineRequest::debit(
                $role,
                $amount,
                description: 'Set-off',
                partyType:   PostingRole::PARTY_CUSTOMER,
                partyId:     (int) $receipt->customer_id,
                partyName:   $receipt->customer?->customer_name,
            );
        }

        // ── Dr: discount given ───────────────────────────────────────────
        //
        // A settlement discount, not a line-level trade discount -- a trade
        // discount never reaches the ledger because the invoice is already
        // recorded net of it. This one clears part of the receivable without
        // cash, so it needs its own line or the invoice would not settle.
        $discount = round((float) $receipt->discount_amount, 2);

        if ($discount > 0) {
            $lines[] = JournalLineRequest::debit(
                PostingRole::DISCOUNT_ALLOWED,
                $discount,
                description: 'Settlement discount allowed',
            );
        }

        // ── Cr: the receivable being cleared, one line per invoice ───────
        foreach ($receipt->allocations as $allocation) {
            $cleared = round(
                (float) $allocation->receipt_amount + (float) $allocation->discount,
                2,
            );

            if ($cleared <= 0) {
                continue;
            }

            $lines[] = JournalLineRequest::credit(
                PostingRole::ACCOUNTS_RECEIVABLE,
                $cleared,
                description: $allocation->so_no ?: null,
                partyType:   PostingRole::PARTY_CUSTOMER,
                partyId:     (int) $receipt->customer_id,
                partyName:   $receipt->customer?->customer_name,
                sourceType:  (string) $allocation->reference_type,
                sourceId:    (int) $allocation->reference_id,
            );
        }

        // ── Cr: advances and overpayments this receipt created ───────────
        //
        // Money received beyond what was owed is not income -- it is a
        // liability until the customer uses it. confirm() has already minted
        // the credit note, so the note is the single source of truth for the
        // amount rather than a second calculation that could disagree.
        $minted = round((float) CustomerCreditNote::where('source_receipt_id', $receipt->id)->sum('amount'), 2);

        if ($minted > 0) {
            $lines[] = JournalLineRequest::credit(
                PostingRole::CUSTOMER_ADVANCE,
                $minted,
                description: 'Advance / overpayment held for customer',
                partyType:   PostingRole::PARTY_CUSTOMER,
                partyId:     (int) $receipt->customer_id,
                partyName:   $receipt->customer?->customer_name,
            );
        }

        return new JournalRequest(
            sourceType:   PostingRole::SOURCE_CUSTOMER_RECEIPT,
            sourceId:     (int) $receipt->id,
            journalDate:  $receipt->receipt_date->toDateTimeImmutable(),
            lines:        $lines,
            journalType:  JournalRequest::TYPE_AUTO,
            documentDate: $receipt->transaction_date?->toDateTimeImmutable(),
            narration:    self::narration($receipt),
        );
    }

    /**
     * Set-off amounts grouped by the account they release.
     *
     *   advance / over_payment -- spending credit we were holding, which
     *                             releases the Customer Advances liability.
     *   sales_return           -- a return credit note. If the return posted
     *                             its own journal it already reversed the sale
     *                             and parked the credit in Customer Advances,
     *                             so spending it releases that. A note from a
     *                             return that never posted (confirmed before
     *                             returns posted) still reverses Sales here.
     *
     * @return array<string, float> role => amount
     */
    private static function setoffTotals(CustomerReceipt $receipt): array
    {
        $totals = [];
        $posted = self::returnNotesAlreadyPosted($receipt);

        foreach ($receipt->setoffs as $setoff) {
            $amount = round((float) $setoff->amount, 2);

            if ($amount <= 0) {
                continue;
            }

            $role = $setoff->setoff_type === CustomerSetoffType::SalesReturn
                && ! isset($posted[(int) $setoff->credit_note_id])
                ? PostingRole::SALES_REVENUE
                : PostingRole::CUSTOMER_ADVANCE;

            $totals[$role] = round(($totals[$role] ?? 0) + $amount, 2);
        }

        return $totals;
    }

    /**
     * Sales-return credit notes whose return already posted its own journal.
     *
     * Since returns post, the return itself reverses the revenue and parks the
     * paid-for part in Customer Advances -- so spending that note releases
     * Customer Advances, like any other credit. A note from a return confirmed
     * before then (or while Finance was off) never touched the ledger and keeps
     * the old treatment: the set-off is what reverses the revenue.
     *
     * @return array<int, true> credit_note_id => true
     */
    private static function returnNotesAlreadyPosted(CustomerReceipt $receipt): array
    {
        $noteIds = $receipt->setoffs
            ->where('setoff_type', CustomerSetoffType::SalesReturn)
            ->pluck('credit_note_id')
            ->filter()
            ->unique()
            ->all();

        if ($noteIds === []) {
            return [];
        }

        $poster = app(JournalPoster::class);
        $posted = [];

        $sources = CustomerCreditNote::whereKey($noteIds)
            ->whereNotNull('source_return_id')
            ->pluck('source_return_id', 'id');

        foreach ($sources as $noteId => $returnId) {
            if ($poster->hasPostingFor(PostingRole::SOURCE_CUSTOMER_RETURN, (int) $returnId)) {
                $posted[(int) $noteId] = true;
            }
        }

        return $posted;
    }

    private static function settlementNote(object $settlement): ?string
    {
        $parts = array_filter([
            $settlement->payment_mode_name ?: null,
            $settlement->reference_no ?: null,
        ]);

        return $parts === [] ? null : implode(' ', $parts);
    }

    private static function narration(CustomerReceipt $receipt): string
    {
        $base = $receipt->receipt_no;

        if ($receipt->is_advance) {
            return $base . ' - advance from customer';
        }

        $count = $receipt->allocations->count();

        return $base . ' - ' . $count . ' ' . ($count === 1 ? 'invoice' : 'invoices') . ' settled';
    }
}
