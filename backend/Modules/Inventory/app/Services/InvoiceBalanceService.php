<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\CustomerReceiptStatus;
use Modules\Inventory\Enums\CustomerReturnStatus;
use Modules\Inventory\Enums\InvoiceStatus;
use Modules\Inventory\Models\CustomerCreditNote;
use Modules\Inventory\Models\Invoice;

/**
 * The single definition of what a customer still owes on an invoice:
 *
 *   outstanding = grand_total
 *               − SUM(confirmed receipt allocations' receipt_amount + discount)
 *               − SUM(confirmed customer returns' applied_to_invoice)
 *
 * A receipt discount is a permanent write-off, so it settles the invoice exactly like
 * received cash. A return credits the invoice only up to what was still owed when it
 * was confirmed (applied_to_invoice) — anything above that was already paid and lives
 * on as an open sales_return credit note instead, so it is never counted twice.
 *
 * Receipts, returns and the receivable reports all read it from here.
 */
class InvoiceBalanceService
{
    /**
     * Money received per invoice from confirmed receipts.
     *
     * @param  array<int> $invoiceIds
     * @return Collection<int, float> invoice id => received
     */
    public function receivedFor(array $invoiceIds): Collection
    {
        if (empty($invoiceIds)) {
            return collect();
        }

        return DB::table('inv_customer_receipt_allocations as a')
            ->join('inv_customer_receipts as r', 'r.id', '=', 'a.receipt_id')
            ->whereIn('a.reference_id', $invoiceIds)
            ->where('a.reference_type', 'invoice')
            ->where('r.status', CustomerReceiptStatus::Confirmed->value)
            ->groupBy('a.reference_id')
            ->select('a.reference_id', DB::raw('SUM(a.receipt_amount + a.discount) as received'))
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->reference_id => (float) $row->received]);
    }

    /**
     * Invoice balance credited by confirmed customer returns.
     *
     * @param  array<int> $invoiceIds
     * @return Collection<int, float> invoice id => returned
     */
    public function returnedFor(array $invoiceIds): Collection
    {
        if (empty($invoiceIds)) {
            return collect();
        }

        return DB::table('inv_customer_returns')
            ->whereIn('invoice_id', $invoiceIds)
            ->where('status', CustomerReturnStatus::Confirmed->value)
            ->whereNull('deleted_at')
            ->groupBy('invoice_id')
            ->select('invoice_id', DB::raw('SUM(applied_to_invoice) as returned'))
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->invoice_id => (float) $row->returned]);
    }

    /** Outstanding for a single invoice. */
    public function outstanding(int $invoiceId): float
    {
        $grandTotal = DB::table('inv_invoices')->where('id', $invoiceId)->value('grand_total');

        if ($grandTotal === null) {
            return 0.0;
        }

        return (float) $grandTotal
            - ($this->receivedFor([$invoiceId])[$invoiceId] ?? 0.0)
            - ($this->returnedFor([$invoiceId])[$invoiceId] ?? 0.0);
    }

    /**
     * Flip fully-settled issued invoices to Paid. Callers run this after the document
     * that settled them (receipt or return) is Confirmed, with the invoice rows locked.
     *
     * @param array<int> $invoiceIds
     */
    public function markPaidIfSettled(array $invoiceIds): void
    {
        foreach ($invoiceIds as $invoiceId) {
            if ($this->outstanding($invoiceId) > 0.01) {
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

    /** Atomically generate the next customer credit note number (must be called inside a DB transaction) */
    public function generateCreditNoteNo(): string
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
}
