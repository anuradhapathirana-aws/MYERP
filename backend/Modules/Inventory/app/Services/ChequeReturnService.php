<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Contracts\ChequeReturnSource;
use App\Contracts\JournalPoster;
use App\Enums\PaymentModeType;
use App\Models\PaymentMode;
use App\Models\SupplierMaster;
use App\Support\Banking\ChequeReturnRequest;
use App\Support\Journal\PostingRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Enums\CreditNoteStatus;
use Modules\Inventory\Enums\CustomerReceiptStatus;
use Modules\Inventory\Enums\InvoiceStatus;
use Modules\Inventory\Enums\SupplierPaymentStatus;
use Modules\Inventory\Models\ChequeReturn;
use Modules\Inventory\Models\ChequeReturnAllocation;
use Modules\Inventory\Models\CustomerCreditNote;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Models\CustomerReceipt;
use Modules\Inventory\Models\CustomerReceiptSettlement;
use Modules\Inventory\Models\Invoice;
use Modules\Inventory\Models\SupplierCreditNote;
use Modules\Inventory\Models\SupplierPayment;
use Modules\Inventory\Models\SupplierPaymentSettlement;
use Modules\Inventory\Posting\ChequeReturnJournal;

/**
 * Takes back a cheque the bank returned, for the Inventory documents that
 * wrote it: a customer receipt (a cheque we received) or a supplier payment
 * (a cheque we issued). Bank Reconciliation calls it through
 * ChequeReturnRouter when it posts a line marked Returned.
 *
 * ── What the cheque had settled ──────────────────────────────────────────────
 * A receipt or payment can mix the cheque with cash, set-offs and discounts,
 * so which part the cheque paid is a convention. The standard one, applied
 * in this order:
 *
 *   1. the credit note the document minted (an advance or overpayment) --
 *      the money that was extra comes back first, while it is unused;
 *   2. the invoices / GRNs it settled, last allocation first, each up to what
 *      the document paid on it (less what an earlier returned cheque of the
 *      same document already reopened);
 *   3. the part of that credit note already spent on another receipt /
 *      payment: the invoices / GRNs it paid there are owed again. That other
 *      document stays confirmed (its cash really came in) but can no longer
 *      be voided.
 *
 * The settlement discount the document gave on each invoice / GRN of step 2
 * is taken back as well: it was given for paying, and the payment failed.
 *
 * ── After the return ────────────────────────────────────────────────────────
 * The return is final, the way standard ERPs treat it: the cheque is closed
 * and its invoices are owed again. Re-presenting the same cheque is a NEW
 * receipt; the customer is flagged by returnedChequesFor().
 */
final class ChequeReturnService implements ChequeReturnSource
{
    private const TOLERANCE_CENTS = 1;

    public function __construct(private readonly InvoiceBalanceService $invoices) {}

    public function sourceTypes(): array
    {
        return [
            PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT,
            PostingRole::SOURCE_SUPPLIER_PAYMENT_SETTLEMENT,
        ];
    }

    /**
     * Cheap checks only, in a few queries for the whole screen: the document
     * is confirmed, the line is a cheque, it was not returned before. Whether
     * the cheque still has something to reopen is checked when it is posted.
     */
    public function blockedReasons(string $sourceType, array $sourceIds): array
    {
        $received    = $sourceType === PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT;
        $settlements = ($received ? CustomerReceiptSettlement::query() : SupplierPaymentSettlement::query())
            ->with($received ? 'receipt' : 'payment')
            ->whereKey($sourceIds)
            ->get()
            ->keyBy('id');

        $returned = ChequeReturn::where('settlement_type', $sourceType)
            ->whereIn('settlement_id', $sourceIds)
            ->pluck('reference', 'settlement_id');

        $types = PaymentMode::typesById($settlements->pluck('payment_mode_id')->filter()->map(fn ($id): int => (int) $id)->all());

        $reasons = [];

        foreach ($sourceIds as $id) {
            $settlement = $settlements->get($id);
            $reasons[$id] = $settlement === null
                ? 'The payment line of this cheque no longer exists.'
                : $this->reasonFor($settlement, $received, $types, $returned->get($id));
        }

        return $reasons;
    }

    public function returnCheque(ChequeReturnRequest $request): ?string
    {
        $received = match ($request->sourceType) {
            PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT => true,
            PostingRole::SOURCE_SUPPLIER_PAYMENT_SETTLEMENT => false,
            default => throw ValidationException::withMessages(['lines' => 'This is not a receipt or payment cheque.']),
        };

        return DB::transaction(function () use ($request, $received): ?string {
            $settlement = ($received ? CustomerReceiptSettlement::query() : SupplierPaymentSettlement::query())
                ->whereKey($request->sourceId)
                ->first();

            if ($settlement === null) {
                throw ValidationException::withMessages(['lines' => 'The payment line of this cheque no longer exists.']);
            }

            // The document is locked first: a void or a second return of the
            // same document queues behind us, then sees the cheque returned.
            $document = $received
                ? CustomerReceipt::with('allocations')->whereKey($settlement->receipt_id)->lockForUpdate()->first()
                : SupplierPayment::with('allocations')->whereKey($settlement->payment_id)->lockForUpdate()->first();

            $settlement->setRelation($received ? 'receipt' : 'payment', $document);

            $types  = PaymentMode::typesById(array_filter([(int) $settlement->payment_mode_id]));
            $before = ChequeReturn::where('settlement_type', $request->sourceType)
                ->where('settlement_id', $settlement->id)
                ->value('reference');

            $reason = $this->reasonFor($settlement, $received, $types, $before);

            if ($reason !== null) {
                throw ValidationException::withMessages(['lines' => $reason]);
            }

            $documentNo = $received ? $document->receipt_no : $document->payment_no;
            $parts      = $this->reopen($document, $received, $this->cents($settlement->amount), (string) $settlement->reference_no, $documentNo);

            /** @var ChequeReturn $return */
            $return = ChequeReturn::create([
                'settlement_type' => $request->sourceType,
                'settlement_id'   => $settlement->id,
                'document_type'   => $received ? ChequeReturn::DOCUMENT_CUSTOMER_RECEIPT : ChequeReturn::DOCUMENT_SUPPLIER_PAYMENT,
                'document_id'     => $document->id,
                'document_no'     => $documentNo,
                'party_type'      => $received ? PostingRole::PARTY_CUSTOMER : PostingRole::PARTY_SUPPLIER,
                'party_id'        => $received ? $document->customer_id : $document->supplier_id,
                'cheque_no'       => $settlement->reference_no,
                'cheque_date'     => $settlement->instrument_date,
                'amount'          => $settlement->amount,
                'return_date'     => $request->returnDate->format('Y-m-d'),
                'reference'       => $request->reference,
                'bank_account_id' => $request->bankAccountId,
                'created_by'      => Auth::id(),
            ]);

            foreach ($parts as $part) {
                $return->allocations()->create([
                    'reference_type'  => $part['type'],
                    'reference_id'    => $part['id'],
                    'via_document_id' => $part['via'] ?? null,
                    'credit_note_id'  => $part['note'] ?? null,
                    'amount'          => $part['cents'] / 100,
                ]);
            }

            if ($received) {
                $this->reopenInvoices(array_column(array_filter(
                    $parts,
                    fn (array $p): bool => in_array($p['type'], ChequeReturnAllocation::REOPENS_INVOICE, true),
                ), 'id'));
            }

            $journalNo = app(JournalPoster::class)->post(ChequeReturnJournal::build(
                $return,
                $received
                    ? CustomerMaster::whereKey($document->customer_id)->value('customer_name')
                    : SupplierMaster::whereKey($document->supplier_id)->value('supplier_name'),
                array_column($parts, 'label'),
            ));

            $return->forceFill(['journal_no' => $journalNo])->save();

            return $journalNo;
        });
    }

    /**
     * The cheques a customer (or supplier) gave that the bank returned,
     * newest first -- the flag the receipt and sales screens show.
     *
     * @return list<array<string, mixed>>
     */
    public function returnedChequesFor(string $partyType, int $partyId, int $limit = 20): array
    {
        return ChequeReturn::where('party_type', $partyType)
            ->where('party_id', $partyId)
            ->orderByDesc('return_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (ChequeReturn $r): array => [
                'cheque_no'   => $r->cheque_no,
                'cheque_date' => $r->cheque_date?->toDateString(),
                'amount'      => number_format((float) $r->amount, 2, '.', ''),
                'return_date' => $r->return_date->toDateString(),
                'document_no' => $r->document_no,
                'reference'   => $r->reference,
                'journal_no'  => $r->journal_no,
            ])
            ->all();
    }

    /**
     * Returned cheques per document, for a list page -- one indexed query.
     *
     * @param  list<int>       $documentIds
     * @return array<int, int> document id => cheques returned
     */
    public function countsByDocument(string $documentType, array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        return ChequeReturn::where('document_type', $documentType)
            ->whereIn('document_id', $documentIds)
            ->groupBy('document_id')
            ->selectRaw('document_id, COUNT(*) as returned')
            ->pluck('returned', 'document_id')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }

    /** Whether any cheque of this document came back -- a returned cheque blocks its void. */
    public function returnedOn(string $documentType, int $documentId): ?string
    {
        $row = ChequeReturn::where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->first(['cheque_no', 'reference']);

        return $row === null ? null : trim('cheque ' . $row->cheque_no) . ' (' . $row->reference . ')';
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * @param array<int, PaymentModeType> $types
     */
    private function reasonFor(Model $settlement, bool $received, array $types, ?string $returnedIn): ?string
    {
        $document = $received ? $settlement->receipt : $settlement->payment;
        $type     = $types[(int) $settlement->payment_mode_id] ?? PaymentModeType::fromLegacyCode($settlement->payment_mode_code);

        return match (true) {
            $returnedIn !== null => 'This cheque was already returned in ' . $returnedIn . '.',
            $type !== PaymentModeType::Cheque => 'Only a cheque can be returned. Void the ' . ($received ? 'receipt' : 'payment') . ' to undo a ' . strtolower($type->label()) . '.',
            $document === null => 'The ' . ($received ? 'receipt' : 'payment') . ' of this cheque no longer exists.',
            $received && $document->status !== CustomerReceiptStatus::Confirmed,
            ! $received && $document->status !== SupplierPaymentStatus::Confirmed
                => ($received ? $document->receipt_no : $document->payment_no) . ' is ' . $document->status->value . ', so its cheque cannot be returned.',
            default => null,
        };
    }

    /**
     * Spread the cheque over what it settled, in this order:
     *
     *   1. the unspent part of the credit notes its document minted (an
     *      advance or overpayment) -- cancelled, under lock;
     *   2. the invoices / GRNs its document paid, last allocation first;
     *   3. the part of those credit notes already spent on OTHER receipts /
     *      payments: the invoices / GRNs that spending paid are owed again
     *      (the standard "reverse the payment, unapply what it funded");
     *   4. the settlement discount its document gave on each invoice / GRN of
     *      step 2, taken back -- it was given for paying, and the payment
     *      failed.
     *
     * Steps 1-3 add up to the cheque. Step 4 is extra: it moves money between
     * the party and the discount account, not the bank.
     *
     * @return list<array{type:string, id:int, cents:int, label:string, via?:int, note?:int}>
     */
    private function reopen(CustomerReceipt|SupplierPayment $document, bool $received, int $chequeCents, string $chequeNo, string $documentNo): array
    {
        $left  = $chequeCents;
        $parts = [];

        $notes = ($received
            ? CustomerCreditNote::where('source_receipt_id', $document->id)
            : SupplierCreditNote::where('source_payment_id', $document->id))
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        // 1. Unspent advance.
        foreach ($notes as $note) {
            $take = min($left, $this->cents($note->remaining_balance));

            if ($take <= 0) {
                continue;
            }

            $remaining = $this->cents($note->remaining_balance) - $take;

            $note->update([
                'remaining_balance' => $remaining / 100,
                'status'            => $remaining <= 0 ? CreditNoteStatus::Exhausted : CreditNoteStatus::Open,
            ]);

            $parts[] = [
                'type'  => $received ? ChequeReturnAllocation::CUSTOMER_CREDIT_NOTE : ChequeReturnAllocation::SUPPLIER_CREDIT_NOTE,
                'id'    => (int) $note->id,
                'cents' => $take,
                'label' => (string) $note->credit_note_no,
            ];

            $left -= $take;
        }

        $refType  = $received ? 'invoice' : 'grn';
        $partType = $received ? ChequeReturnAllocation::INVOICE : ChequeReturnAllocation::GRN;
        $amount   = $received ? 'receipt_amount' : 'payment_amount';

        // 2. What the document paid per invoice / GRN, last allocation first.
        $paid      = [];
        $discounts = [];

        foreach ($document->allocations->where('reference_type', $refType)->sortByDesc('id') as $allocation) {
            $id             = (int) $allocation->reference_id;
            $paid[$id]      = ($paid[$id] ?? 0) + $this->cents($allocation->{$amount});
            $discounts[$id] = ($discounts[$id] ?? 0) + $this->cents($allocation->discount);
        }

        $already  = $this->alreadyReopened($received, (int) $document->id, $partType);
        $labels   = $this->documentNumbers($received, array_keys($paid));
        $reopened = [];

        foreach ($paid as $id => $cents) {
            $take = min($left, $cents - ($already[$id] ?? 0));

            if ($take <= 0) {
                continue;
            }

            $parts[]       = ['type' => $partType, 'id' => $id, 'cents' => $take, 'label' => $labels[$id] ?? ''];
            $reopened[$id] = true;
            $left         -= $take;
        }

        // 3. The advance this cheque created, spent elsewhere.
        if ($left > 0) {
            foreach ($this->reopenSpentAdvances($notes, $received, $left) as $part) {
                $parts[] = $part;
                $left   -= $part['cents'];
            }
        }

        // Sub-cent residue from 4-decimal allocations: the bank line is the
        // cheque, so the reopened parts must add up to it exactly.
        if ($left > 0 && $left <= self::TOLERANCE_CENTS && $parts !== []) {
            $parts[array_key_last($parts)]['cents'] += $left;
            $left = 0;
        }

        if ($left > 0) {
            throw ValidationException::withMessages([
                'lines' => 'Cheque ' . $chequeNo . ' of ' . $documentNo . ' cannot be returned: '
                    . number_format($left / 100, 2) . ' of it has nothing left to reopen. '
                    . 'An invoice or GRN it paid (or its advance paid) was voided or credited since. Check those documents first.',
            ]);
        }

        // 4. Discounts given for paying, taken back once per document.
        $discountType  = $received ? ChequeReturnAllocation::INVOICE_DISCOUNT : ChequeReturnAllocation::GRN_DISCOUNT;
        $discountTaken = $this->alreadyReopened($received, (int) $document->id, $discountType);

        foreach (array_keys($reopened) as $id) {
            $cents = ($discounts[$id] ?? 0) - ($discountTaken[$id] ?? 0);

            if ($cents > 0) {
                $parts[] = ['type' => $discountType, 'id' => $id, 'cents' => $cents, 'label' => $labels[$id] ?? ''];
            }
        }

        return $parts;
    }

    /**
     * Step 3 of reopen(): the credit notes this cheque's document minted, as
     * far as other confirmed receipts / payments spent them. Each spending
     * document's invoices / GRNs reopen, last allocation first, up to what it
     * spent from the note and what it paid on each. Cash on those documents
     * stays paid: only the advance's share comes back.
     *
     * @param  \Illuminate\Support\Collection<int, CustomerCreditNote|SupplierCreditNote> $notes
     * @return list<array{type:string, id:int, cents:int, label:string, via:int, note:int}>
     */
    private function reopenSpentAdvances($notes, bool $received, int $left): array
    {
        $parts    = [];
        $partType = $received ? ChequeReturnAllocation::INVOICE : ChequeReturnAllocation::GRN;
        $refType  = $received ? 'invoice' : 'grn';
        $amount   = $received ? 'receipt_amount' : 'payment_amount';
        $docKey   = $received ? 'receipt_id' : 'payment_id';

        foreach ($notes as $note) {
            if ($left <= 0) {
                break;
            }

            $spending = DB::table($received ? 'inv_customer_receipt_setoffs as s' : 'inv_supplier_payment_setoffs as s')
                ->join($received ? 'inv_customer_receipts as d' : 'inv_supplier_payments as d', 'd.id', '=', 's.' . $docKey)
                ->where('s.credit_note_id', $note->id)
                ->where('d.status', $received ? CustomerReceiptStatus::Confirmed->value : SupplierPaymentStatus::Confirmed->value)
                ->groupBy('s.' . $docKey)
                ->orderByDesc('s.' . $docKey)
                ->select('s.' . $docKey . ' as document_id', DB::raw('SUM(s.amount) as spent'))
                ->get();

            foreach ($spending as $spend) {
                if ($left <= 0) {
                    break;
                }

                $viaId = (int) $spend->document_id;
                $room  = $this->cents($spend->spent) - $this->reopenedThroughNote($received, $viaId, (int) $note->id);

                if ($room <= 0) {
                    continue;
                }

                $via = $received
                    ? CustomerReceipt::with('allocations')->whereKey($viaId)->lockForUpdate()->first()
                    : SupplierPayment::with('allocations')->whereKey($viaId)->lockForUpdate()->first();

                if ($via === null) {
                    continue;
                }

                $paid = [];

                foreach ($via->allocations->where('reference_type', $refType)->sortByDesc('id') as $allocation) {
                    $paid[(int) $allocation->reference_id] = ($paid[(int) $allocation->reference_id] ?? 0) + $this->cents($allocation->{$amount});
                }

                $already = $this->alreadyReopened($received, $viaId, $partType);
                $labels  = $this->documentNumbers($received, array_keys($paid));

                foreach ($paid as $id => $cents) {
                    $take = min($left, $room, $cents - ($already[$id] ?? 0));

                    if ($take <= 0) {
                        continue;
                    }

                    $parts[] = [
                        'type'  => $partType,
                        'id'    => $id,
                        'cents' => $take,
                        'label' => ($labels[$id] ?? '') . ' (advance ' . $note->credit_note_no . ' used on '
                            . ($received ? $via->receipt_no : $via->payment_no) . ')',
                        'via'   => $viaId,
                        'note'  => (int) $note->id,
                    ];

                    $left -= $take;
                    $room -= $take;
                }
            }
        }

        return $parts;
    }

    /** Cents of one credit note's spending on one document that earlier returns already reopened. */
    private function reopenedThroughNote(bool $received, int $viaId, int $noteId): int
    {
        $sum = DB::table('inv_cheque_return_allocations as a')
            ->join('inv_cheque_returns as r', 'r.id', '=', 'a.cheque_return_id')
            ->where('r.document_type', $received ? ChequeReturn::DOCUMENT_CUSTOMER_RECEIPT : ChequeReturn::DOCUMENT_SUPPLIER_PAYMENT)
            ->where('a.via_document_id', $viaId)
            ->where('a.credit_note_id', $noteId)
            ->sum('a.amount');

        return $this->cents($sum);
    }

    /**
     * What earlier returned cheques already reopened on each invoice / GRN,
     * counted against the document that paid it -- the cheque's own document,
     * or the one that spent its advance (via_document_id).
     *
     * @return array<int, int> reference id => cents
     */
    private function alreadyReopened(bool $received, int $documentId, string $partType): array
    {
        return DB::table('inv_cheque_return_allocations as a')
            ->join('inv_cheque_returns as r', 'r.id', '=', 'a.cheque_return_id')
            ->where('r.document_type', $received ? ChequeReturn::DOCUMENT_CUSTOMER_RECEIPT : ChequeReturn::DOCUMENT_SUPPLIER_PAYMENT)
            ->where(fn ($q) => $q->where('a.via_document_id', $documentId)
                ->orWhere(fn ($w) => $w->whereNull('a.via_document_id')->where('r.document_id', $documentId)))
            ->where('a.reference_type', $partType)
            ->groupBy('a.reference_id')
            ->select('a.reference_id', DB::raw('SUM(a.amount) as reopened'))
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->reference_id => $this->cents($row->reopened)])
            ->all();
    }

    /**
     * Why a receipt / payment cannot be voided because a returned cheque
     * reopened invoices / GRNs it paid with that cheque's advance -- voiding it
     * as well would reopen them twice. Null when nothing did.
     */
    public function reopenedThrough(string $documentType, int $documentId): ?string
    {
        $row = DB::table('inv_cheque_return_allocations as a')
            ->join('inv_cheque_returns as r', 'r.id', '=', 'a.cheque_return_id')
            ->where('r.document_type', $documentType)
            ->where('a.via_document_id', $documentId)
            ->first(['r.cheque_no', 'r.document_no', 'r.reference']);

        return $row === null
            ? null
            : trim('cheque ' . $row->cheque_no) . ' of ' . $row->document_no . ' (' . $row->reference . ')';
    }

    /**
     * @param  list<int> $ids
     * @return array<int, string>
     */
    private function documentNumbers(bool $received, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $received
            ? DB::table('inv_invoices')->whereIn('id', $ids)->pluck('invoice_no', 'id')->all()
            : DB::table('inv_goods_received_notes')->whereIn('id', $ids)->pluck('grn_no', 'id')->all();
    }

    /**
     * A Paid invoice the cheque had settled is owed again.
     *
     * @param list<int> $invoiceIds
     */
    private function reopenInvoices(array $invoiceIds): void
    {
        if ($invoiceIds === []) {
            return;
        }

        /** @var Collection<int, Invoice> $invoices */
        $invoices = Invoice::whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get();

        foreach ($invoices as $invoice) {
            if ($invoice->status === InvoiceStatus::Paid && $this->invoices->outstanding((int) $invoice->id) > 0.01) {
                $invoice->update(['status' => InvoiceStatus::Issued, 'paid_at' => null]);
            }
        }
    }

    private function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
