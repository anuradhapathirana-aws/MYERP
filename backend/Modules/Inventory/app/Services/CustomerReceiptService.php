<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\CustomerReceiptData;
use Modules\Inventory\Enums\CreditNoteStatus;
use Modules\Inventory\Enums\CustomerCreditNoteType;
use Modules\Inventory\Enums\CustomerReceiptStatus;
use Modules\Inventory\Enums\CustomerSetoffType;
use Modules\Inventory\Enums\InvoiceStatus;
use Modules\Inventory\Models\CustomerCreditNote;
use Modules\Inventory\Models\CustomerReceipt;
use Modules\Inventory\Models\CustomerReceiptAllocation;
use Modules\Inventory\Models\CustomerReceiptSetoff;
use Modules\Inventory\Models\CustomerReceiptSettlement;
use Modules\Inventory\Models\Invoice;
use Modules\Inventory\Models\PaymentMode;

/**
 * Customer receipts — money received from customers against issued invoices.
 * Mirrors SupplierPaymentService (allocations / setoffs / settlements, draft →
 * confirm lifecycle). On confirm, any issued invoice whose outstanding reaches
 * zero automatically flips to Paid.
 */
class CustomerReceiptService
{
    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = CustomerReceipt::orderByDesc('receipt_date')->orderByDesc('id');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term): void {
                $q->where('receipt_no', 'like', $term)
                  ->orWhere('reference_no', 'like', $term);
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('receipt_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('receipt_date', '<=', $filters['date_to']);
        }

        $paginator = $query->paginate($perPage);
        $this->attachCustomerSnapshots($paginator->items());

        return $paginator;
    }

    public function find(int $id): CustomerReceipt
    {
        $receipt = CustomerReceipt::with(['allocations', 'setoffs.creditNote', 'settlements'])->findOrFail($id);
        $this->attachCustomerSnapshots([$receipt]);
        $this->attachInvoiceNumbers($receipt);

        return $receipt;
    }

    /**
     * Outstanding issued invoices for a customer — data source for the receipt form's
     * invoice table. Draft invoices can't receive money; Paid ones owe nothing.
     * @return array<array<string, mixed>>
     */
    public function getOutstandingInvoicesForCustomer(int $customerId): array
    {
        $invoices = DB::table('inv_invoices as i')
            ->leftJoin('inv_sales_orders as so', 'so.id', '=', 'i.so_id')
            ->leftJoin('inv_delivery_orders as do', 'do.id', '=', 'i.do_id')
            ->where('i.customer_id', $customerId)
            ->where('i.status', InvoiceStatus::Issued->value)
            ->whereNull('i.deleted_at')
            ->orderBy('i.invoice_date')
            ->select('i.id', 'i.invoice_no', 'i.invoice_date', 'i.due_date', 'i.grand_total', 'so.so_no', 'do.do_no')
            ->get();

        if ($invoices->isEmpty()) {
            return [];
        }

        $receivedByInvoice = DB::table('inv_customer_receipt_allocations as a')
            ->join('inv_customer_receipts as r', 'r.id', '=', 'a.receipt_id')
            ->whereIn('a.reference_id', $invoices->pluck('id')->all())
            ->where('a.reference_type', 'invoice')
            ->where('r.status', CustomerReceiptStatus::Confirmed->value)
            ->groupBy('a.reference_id')
            // receipt_amount + discount: a discount is a permanent write-off, so it must
            // reduce outstanding exactly like received cash does.
            ->select('a.reference_id', DB::raw('SUM(a.receipt_amount + a.discount) as received'))
            ->get()
            ->pluck('received', 'reference_id');

        $result = [];

        foreach ($invoices as $invoice) {
            $received    = (float) ($receivedByInvoice[$invoice->id] ?? 0);
            $outstanding = (float) $invoice->grand_total - $received;

            if ($outstanding <= 0) {
                continue;
            }

            $result[] = [
                'invoice_id'   => $invoice->id,
                'invoice_no'   => $invoice->invoice_no,
                'invoice_date' => $invoice->invoice_date,
                'so_no'        => $invoice->so_no,
                'do_no'        => $invoice->do_no,
                'due_date'     => $invoice->due_date,
                'amount'       => (float) $invoice->grand_total,
                'outstanding'  => $outstanding,
            ];
        }

        return $result;
    }

    /** @return \Illuminate\Support\Collection<int, CustomerCreditNote> */
    public function getOpenCreditNotesForCustomer(int $customerId, ?string $type = null)
    {
        $query = CustomerCreditNote::where('customer_id', $customerId)
            ->where('status', CreditNoteStatus::Open)
            ->where('remaining_balance', '>', 0);

        if ($type) {
            $query->where('credit_type', $type);
        }

        return $query->orderByDesc('id')->get();
    }

    public function create(CustomerReceiptData $data): CustomerReceipt
    {
        return DB::transaction(function () use ($data): CustomerReceipt {
            $receipt = CustomerReceipt::create([
                'receipt_no'       => $this->generateReceiptNo(),
                'receipt_date'     => $data->receiptDate,
                'transaction_date' => $data->transactionDate,
                'reference_no'     => $data->referenceNo,
                'customer_id'      => $data->customerId,
                'receipt_remark'   => $data->receiptRemark,
                'is_advance'       => $data->isAdvance,
                'advance_amount'   => $data->isAdvance ? $data->advanceAmount : 0,
                'status'           => CustomerReceiptStatus::Draft,
                'created_by'       => auth()->id(),
            ]);

            $this->finalizeAmounts($receipt, $data);

            $receipt->load(['allocations', 'setoffs.creditNote', 'settlements']);
            $this->attachCustomerSnapshots([$receipt]);
            $this->attachInvoiceNumbers($receipt);

            return $receipt;
        });
    }

    public function update(CustomerReceipt $receipt, CustomerReceiptData $data): CustomerReceipt
    {
        if ($receipt->status !== CustomerReceiptStatus::Draft) {
            abort(422, 'Only draft receipts can be edited.');
        }

        return DB::transaction(function () use ($receipt, $data): CustomerReceipt {
            $receipt->update([
                'receipt_date'     => $data->receiptDate,
                'transaction_date' => $data->transactionDate,
                'reference_no'     => $data->referenceNo,
                'customer_id'      => $data->customerId,
                'receipt_remark'   => $data->receiptRemark,
                'is_advance'       => $data->isAdvance,
                'advance_amount'   => $data->isAdvance ? $data->advanceAmount : 0,
            ]);

            $this->finalizeAmounts($receipt, $data);

            $receipt->load(['allocations', 'setoffs.creditNote', 'settlements']);
            $this->attachCustomerSnapshots([$receipt]);
            $this->attachInvoiceNumbers($receipt);

            return $receipt;
        });
    }

    /**
     * Sync allocations/setoffs/settlements, then validate and persist the header totals.
     * Total Receivable must be fully covered by setoffs + settlement lines before saving
     * (Remaining ≤ 0) — underfunding is blocked, but overfunding is allowed: the excess
     * becomes an over_payment credit note when the receipt is confirmed (see confirm()).
     */
    private function finalizeAmounts(CustomerReceipt $receipt, CustomerReceiptData $data): void
    {
        [$grossAmount, $discountAmount] = $this->syncAllocations($receipt, $data->allocations);
        $setoffAmount     = $this->syncSetoffs($receipt, $data->setoffs);
        $settlementAmount = $this->syncSettlements($receipt, $data->settlements);

        $target = $data->isAdvance ? (float) $data->advanceAmount : $grossAmount;

        if ($target - $setoffAmount - $settlementAmount > 0.01) {
            abort(422, 'Receipt is underfunded — cover the full Total Receivable with Setoffs and/or Receipt Details before saving.');
        }

        $receipt->update([
            'gross_amount'    => $grossAmount,
            'discount_amount' => $discountAmount,
            'setoff_amount'   => $setoffAmount,
            'net_amount'      => $settlementAmount,
        ]);
    }

    /**
     * Confirm a draft receipt: locks affected invoices and credit notes, recomputes
     * outstanding authoritatively, converts overpayment into a credit note, consumes
     * setoff credit notes, posts a standalone advance's credit note, and flips any
     * fully-received issued invoice to Paid.
     */
    public function confirm(CustomerReceipt $receipt): CustomerReceipt
    {
        if ($receipt->status !== CustomerReceiptStatus::Draft) {
            abort(422, 'Only draft receipts can be confirmed.');
        }

        return DB::transaction(function () use ($receipt): CustomerReceipt {
            $receipt->load(['allocations', 'setoffs', 'settlements']);

            $invoiceIds = $receipt->allocations
                ->where('reference_type', 'invoice')
                ->pluck('reference_id')
                ->unique()
                ->sort()
                ->values()
                ->all();

            if (!empty($invoiceIds)) {
                // Lock in a consistent id order to avoid deadlocks against concurrent confirms
                DB::table('inv_invoices')
                    ->whereIn('id', $invoiceIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            // Invoice-row overpayment: a row's receipt_amount exceeding what that specific
            // invoice still owes (outstanding minus its own discount).
            $overpaymentExcess = 0.0;

            foreach ($receipt->allocations as $allocation) {
                if ($allocation->reference_type !== 'invoice') {
                    continue;
                }

                $outstanding   = $this->computeOutstanding((int) $allocation->reference_id);
                $discount      = (float) $allocation->discount;
                $maxReceivable = max(0.0, $outstanding - $discount);
                $receiptAmount = (float) $allocation->receipt_amount;

                // Compare against maxReceivable (outstanding minus this row's own discount), not
                // raw outstanding — otherwise the discounted portion is silently excluded from
                // the credit note instead of being available for setoff. Rounded to cents with
                // a 1c tolerance so float noise from invoice totals stored at 4-decimal precision
                // never spawns a phantom overpayment credit note.
                $rowExcess = round($receiptAmount - $maxReceivable, 2);
                if ($rowExcess > 0.01) {
                    $overpaymentExcess += $rowExcess;
                }
            }

            $setoffAmountTotal = 0.0;

            foreach ($receipt->setoffs as $setoff) {
                $amount = (float) $setoff->amount;
                $setoffAmountTotal += $amount;

                if ($setoff->setoff_type === CustomerSetoffType::SalesReturn) {
                    $creditNote = CustomerCreditNote::create([
                        'credit_note_no'    => $this->generateCreditNoteNo(),
                        'customer_id'       => $receipt->customer_id,
                        'credit_type'       => CustomerCreditNoteType::SalesReturn,
                        'amount'            => $amount,
                        'remaining_balance' => 0,
                        'remark'            => $setoff->remark,
                        'status'            => CreditNoteStatus::Exhausted,
                        'source_receipt_id' => null,
                        'created_by'        => auth()->id(),
                    ]);

                    $setoff->update(['credit_note_id' => $creditNote->id]);
                    continue;
                }

                // over_payment / advance setoffs consume an existing credit note's balance
                $creditNote = CustomerCreditNote::whereKey($setoff->credit_note_id)
                    ->lockForUpdate()
                    ->first();

                if (!$creditNote) {
                    abort(422, 'Referenced credit note no longer exists.');
                }

                if ((float) $creditNote->remaining_balance < $amount) {
                    abort(422, "Insufficient credit note balance for a setoff of {$amount}.");
                }

                $newBalance = (float) $creditNote->remaining_balance - $amount;
                $creditNote->update([
                    'remaining_balance' => $newBalance,
                    'status'            => $newBalance <= 0.0001 ? CreditNoteStatus::Exhausted : CreditNoteStatus::Open,
                ]);
            }

            if ($receipt->is_advance) {
                CustomerCreditNote::create([
                    'credit_note_no'    => $this->generateCreditNoteNo(),
                    'customer_id'       => $receipt->customer_id,
                    'credit_type'       => CustomerCreditNoteType::Advance,
                    'amount'            => (float) $receipt->advance_amount,
                    'remaining_balance' => (float) $receipt->advance_amount,
                    'remark'            => "Advance receipt {$receipt->receipt_no}.",
                    'status'            => CreditNoteStatus::Open,
                    'source_receipt_id' => $receipt->id,
                    'created_by'        => auth()->id(),
                ]);
            }

            $grossAmount    = (float) $receipt->allocations->sum('receipt_amount');
            $discountAmount = (float) $receipt->allocations->sum('discount');
            $netAmount      = (float) $receipt->settlements->sum('amount');

            // Receipt-level overfunding: Setoffs + Receipt Details together exceed what was
            // actually owed (Total Receivable / advance amount) — not tied to any specific
            // invoice row. Merged into the same aggregate over_payment credit note as any
            // invoice-row overpayment above.
            $target = $receipt->is_advance ? (float) $receipt->advance_amount : $grossAmount;
            $overpaymentExcess += max(0.0, round($setoffAmountTotal + $netAmount - $target, 2));
            $overpaymentExcess = round($overpaymentExcess, 2);

            if ($overpaymentExcess > 0.01) {
                CustomerCreditNote::create([
                    'credit_note_no'    => $this->generateCreditNoteNo(),
                    'customer_id'       => $receipt->customer_id,
                    'credit_type'       => CustomerCreditNoteType::OverPayment,
                    'amount'            => $overpaymentExcess,
                    'remaining_balance' => $overpaymentExcess,
                    'remark'            => "Auto-generated from overpayment on {$receipt->receipt_no}.",
                    'status'            => CreditNoteStatus::Open,
                    'source_receipt_id' => $receipt->id,
                    'created_by'        => auth()->id(),
                ]);
            }

            $receipt->update([
                'gross_amount'    => $grossAmount,
                'discount_amount' => $discountAmount,
                'setoff_amount'   => $setoffAmountTotal,
                'net_amount'      => $netAmount,
                'status'          => CustomerReceiptStatus::Confirmed,
                'confirmed_at'    => now(),
            ]);

            $this->markSettledInvoicesPaid($invoiceIds);

            $fresh = $receipt->fresh(['allocations', 'setoffs.creditNote', 'settlements']);
            $this->attachCustomerSnapshots([$fresh]);
            $this->attachInvoiceNumbers($fresh);

            return $fresh;
        });
    }

    public function delete(CustomerReceipt $receipt): void
    {
        if ($receipt->status !== CustomerReceiptStatus::Draft) {
            abort(422, 'Only draft receipts can be deleted.');
        }

        $receipt->delete();
    }

    /**
     * Flip fully-received issued invoices to Paid. Runs after the receipt turns
     * Confirmed, so computeOutstanding now includes this receipt's allocations.
     * Rows were already locked at the top of confirm().
     * @param array<int> $invoiceIds
     */
    private function markSettledInvoicesPaid(array $invoiceIds): void
    {
        foreach ($invoiceIds as $invoiceId) {
            if ($this->computeOutstanding($invoiceId) > 0.01) {
                continue;
            }

            Invoice::whereKey($invoiceId)
                ->where('status', InvoiceStatus::Issued->value)
                ->update([
                    'status'  => InvoiceStatus::Paid->value,
                    'paid_at' => now(),
                ]);
        }
    }

    /** Preview the next receipt number (non-locking, for display only) */
    public function nextReceiptNo(): string
    {
        $prefix = 'RCP-';

        $last = CustomerReceipt::withTrashed()
            ->where('receipt_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('receipt_no');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Atomically generate the next receipt number (must be called inside a DB transaction) */
    private function generateReceiptNo(): string
    {
        $prefix = 'RCP-';

        $last = CustomerReceipt::withTrashed()
            ->where('receipt_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('receipt_no');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Atomically generate the next credit note number (must be called inside a DB transaction) */
    private function generateCreditNoteNo(): string
    {
        $prefix = 'CCN-';

        $last = CustomerCreditNote::where('credit_note_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('credit_note_no');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Outstanding for a single invoice, based only on confirmed receipts — reused by the list endpoint and confirm(). */
    private function computeOutstanding(int $invoiceId): float
    {
        $invoice = DB::table('inv_invoices')->where('id', $invoiceId)->first(['grand_total']);

        if (!$invoice) {
            return 0.0;
        }

        // receipt_amount + discount: a discount is a permanent write-off, so it must
        // reduce outstanding exactly like received cash does.
        $received = DB::table('inv_customer_receipt_allocations as a')
            ->join('inv_customer_receipts as r', 'r.id', '=', 'a.receipt_id')
            ->where('a.reference_type', 'invoice')
            ->where('a.reference_id', $invoiceId)
            ->where('r.status', CustomerReceiptStatus::Confirmed->value)
            ->selectRaw('COALESCE(SUM(a.receipt_amount + a.discount), 0) as total')
            ->value('total');

        return (float) $invoice->grand_total - (float) $received;
    }

    /**
     * Validates discount against live outstanding server-side (never trusts a client-sent
     * discount blindly) and persists the allocation rows. receipt_amount defaults to
     * receiving the invoice in full (outstanding − discount) but can be a smaller
     * client-supplied amount — a genuine partial receipt — leaving the remainder
     * outstanding for a future receipt.
     *
     * @param array<array{reference_type:string, reference_id:int, due_date:?string, discount:?float, receipt_amount:?float, line_remark:?string}> $allocations
     * @return array{0: float, 1: float} [grossAmount, discountAmount]
     */
    private function syncAllocations(CustomerReceipt $receipt, array $allocations): array
    {
        $receipt->allocations()->delete();

        $invoiceIds = collect($allocations)->pluck('reference_id')->filter()->unique()->values()->all();

        $invoices = empty($invoiceIds) ? collect() : DB::table('inv_invoices as i')
            ->leftJoin('inv_sales_orders as so', 'so.id', '=', 'i.so_id')
            ->leftJoin('inv_delivery_orders as do', 'do.id', '=', 'i.do_id')
            ->whereIn('i.id', $invoiceIds)
            ->select('i.id', 'i.invoice_date', 'i.due_date', 'i.grand_total', 'so.so_no', 'do.do_no')
            ->get()
            ->keyBy('id');

        $rows          = [];
        $grossAmount   = 0.0;
        $discountTotal = 0.0;

        foreach ($allocations as $row) {
            $invoiceId = (int) ($row['reference_id'] ?? 0);
            if ($invoiceId <= 0) {
                continue;
            }

            $outstanding = $this->computeOutstanding($invoiceId);
            $discount    = (float) ($row['discount'] ?? 0);

            if ($discount > $outstanding) {
                abort(422, "Discount cannot exceed the outstanding amount for invoice #{$invoiceId}.");
            }

            $maxReceivable = $outstanding - $discount;

            $receiptAmount = array_key_exists('receipt_amount', $row) && $row['receipt_amount'] !== null
                ? (float) $row['receipt_amount']
                : $maxReceivable;

            // No upper bound here — receiving more than maxReceivable is a deliberate
            // overpayment. confirm() converts the excess into an over_payment credit note,
            // available for setoff on a future receipt, rather than rejecting it.
            if ($receiptAmount < 0) {
                abort(422, "Receipt amount for invoice #{$invoiceId} cannot be negative.");
            }

            if ($receiptAmount <= 0 && $discount <= 0) {
                continue;
            }

            $invoice = $invoices[$invoiceId] ?? null;

            $rows[] = [
                'receipt_id'         => $receipt->id,
                'reference_type'     => $row['reference_type'] ?? 'invoice',
                'reference_id'       => $invoiceId,
                'invoice_date'       => $invoice->invoice_date ?? null,
                'so_no'              => $invoice->so_no ?? null,
                'do_no'              => $invoice->do_no ?? null,
                'invoice_amount'     => $invoice->grand_total ?? 0,
                'due_date'           => $row['due_date'] ?? ($invoice->due_date ?? null),
                'outstanding_before' => $outstanding,
                'discount'           => $discount,
                'receipt_amount'     => $receiptAmount,
                'line_remark'        => $row['line_remark'] ?? null,
                'created_at'         => now(),
                'updated_at'         => now(),
            ];

            $grossAmount   += $receiptAmount;
            $discountTotal += $discount;
        }

        if (!empty($rows)) {
            CustomerReceiptAllocation::insert($rows);
        }

        return [$grossAmount, $discountTotal];
    }

    /**
     * @param array<array{setoff_type:string, credit_note_id:?int, amount:float, remark:?string}> $setoffs
     * @return float total setoff amount
     */
    private function syncSetoffs(CustomerReceipt $receipt, array $setoffs): float
    {
        $receipt->setoffs()->delete();

        $rows  = [];
        $total = 0.0;

        foreach ($setoffs as $row) {
            $amount = (float) ($row['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $rows[] = [
                'receipt_id'     => $receipt->id,
                'setoff_type'    => $row['setoff_type'],
                'credit_note_id' => !empty($row['credit_note_id']) ? (int) $row['credit_note_id'] : null,
                'amount'         => $amount,
                'remark'         => $row['remark'] ?? null,
                'created_at'     => now(),
                'updated_at'     => now(),
            ];

            $total += $amount;
        }

        if (!empty($rows)) {
            CustomerReceiptSetoff::insert($rows);
        }

        return $total;
    }

    /**
     * @param array<array{payment_mode_id:int, amount:float, bank_name:?string, bank_account_no:?string, reference_no:?string, instrument_date:?string, is_thirdparty:?bool, remark:?string}> $settlements
     * @return float total settlement amount
     */
    private function syncSettlements(CustomerReceipt $receipt, array $settlements): float
    {
        $receipt->settlements()->delete();

        $modeIds = collect($settlements)->pluck('payment_mode_id')->filter()->unique()->values()->all();
        $modes   = empty($modeIds) ? collect() : PaymentMode::whereIn('id', $modeIds)->get()->keyBy('id');

        $rows  = [];
        $total = 0.0;

        foreach ($settlements as $row) {
            $modeId = (int) ($row['payment_mode_id'] ?? 0);
            $amount = (float) ($row['amount'] ?? 0);
            if ($modeId <= 0 || $amount <= 0) {
                continue;
            }

            $mode = $modes[$modeId] ?? null;

            $rows[] = [
                'receipt_id'        => $receipt->id,
                'payment_mode_id'   => $modeId,
                'payment_mode_code' => $mode->code               ?? '',
                'payment_mode_name' => $mode->payment_mode_name  ?? '',
                'amount'            => $amount,
                'bank_name'         => $row['bank_name']         ?? null,
                'bank_account_no'   => $row['bank_account_no']   ?? null,
                'reference_no'      => $row['reference_no']      ?? null,
                'instrument_date'   => $row['instrument_date']   ?? null,
                'is_thirdparty'     => (bool) ($row['is_thirdparty'] ?? false),
                'remark'            => $row['remark']            ?? null,
                'created_at'        => now(),
                'updated_at'        => now(),
            ];

            $total += $amount;
        }

        if (!empty($rows)) {
            CustomerReceiptSettlement::insert($rows);
        }

        return $total;
    }

    /**
     * Attach the live invoice_no to loaded allocation rows (display only — the allocation
     * snapshot doesn't persist it). Allocations are never saved after this runs.
     */
    private function attachInvoiceNumbers(CustomerReceipt $receipt): void
    {
        $invoiceIds = $receipt->allocations
            ->where('reference_type', 'invoice')
            ->pluck('reference_id')
            ->unique()
            ->values()
            ->all();

        if (empty($invoiceIds)) {
            return;
        }

        $invoiceNos = DB::table('inv_invoices')
            ->whereIn('id', $invoiceIds)
            ->pluck('invoice_no', 'id');

        foreach ($receipt->allocations as $allocation) {
            if ($allocation->reference_type === 'invoice') {
                $allocation->setAttribute('invoice_no', $invoiceNos[$allocation->reference_id] ?? null);
            }
        }
    }

    /** @param array<CustomerReceipt> $receipts */
    private function attachCustomerSnapshots(array $receipts): void
    {
        $customerIds = collect($receipts)->pluck('customer_id')->filter()->unique()->values()->all();
        if (empty($customerIds)) {
            return;
        }

        $customers = DB::table('inv_customer_masters')
            ->whereIn('id', $customerIds)
            ->get(['id', 'customer_name', 'customer_code'])
            ->keyBy('id');

        foreach ($receipts as $receipt) {
            $c = $customers[$receipt->customer_id] ?? null;
            // setRelation (not setAttribute) — keeps this out of $attributes so a later
            // save()/update() on this same instance never tries to persist it as a column.
            $receipt->setRelation('customer', $c ? [
                'id'            => $c->id,
                'name'          => $c->customer_name,
                'customer_code' => $c->customer_code,
            ] : null);
        }
    }
}
