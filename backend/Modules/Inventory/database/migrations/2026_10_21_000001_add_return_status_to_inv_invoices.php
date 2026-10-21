<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Half a step of the 4-decimal quantity input — below that a line is fully returned. */
    private const QTY_TOLERANCE = 0.00005;

    /**
     * Invoice return tag (none | partial | full) + the "returned" invoice status.
     *
     * Back-fills both from confirmed customer returns:
     *  - return_status: full when every invoice line's confirmed returned quantity reaches
     *    the invoiced quantity, partial when anything was returned.
     *  - status: an invoice the return flow flipped to Paid although no receipt ever
     *    touched it (settled by returns alone) becomes Returned, paid_at cleared.
     *
     * Only invoices with a confirmed return are read, so this is a handful of rows.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('inv_invoices', 'return_status')) {
            Schema::table('inv_invoices', function (Blueprint $table): void {
                $table->string('return_status', 10)->default('none')->after('status');
            });
        }

        $invoiceIds = DB::table('inv_customer_returns')
            ->where('status', 'confirmed')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('invoice_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($invoiceIds)) {
            return;
        }

        $invoiced = DB::table('inv_invoice_items')
            ->whereIn('invoice_id', $invoiceIds)
            ->get(['id', 'invoice_id', 'quantity'])
            ->groupBy('invoice_id');

        $returned = DB::table('inv_customer_return_items as ri')
            ->join('inv_customer_returns as r', 'r.id', '=', 'ri.return_id')
            ->whereIn('r.invoice_id', $invoiceIds)
            ->where('r.status', 'confirmed')
            ->whereNull('r.deleted_at')
            ->groupBy('ri.invoice_item_id')
            ->selectRaw('ri.invoice_item_id, SUM(ri.quantity) as qty')
            ->pluck('qty', 'invoice_item_id');

        $full = [];
        foreach ($invoiced as $invoiceId => $items) {
            if ($items->every(fn ($item) => (float) $item->quantity - (float) ($returned[$item->id] ?? 0) <= self::QTY_TOLERANCE)) {
                $full[] = (int) $invoiceId;
            }
        }

        DB::table('inv_invoices')->whereIn('id', $invoiceIds)->update(['return_status' => 'partial']);
        DB::table('inv_invoices')->whereIn('id', $full)->update(['return_status' => 'full']);

        $this->markSettledByReturnsOnly($invoiceIds);
    }

    public function down(): void
    {
        // Before this migration a return-settled invoice was Paid.
        DB::table('inv_invoices')
            ->where('status', 'returned')
            ->update(['status' => 'paid', 'paid_at' => DB::raw('updated_at')]);

        if (Schema::hasColumn('inv_invoices', 'return_status')) {
            Schema::table('inv_invoices', function (Blueprint $table): void {
                $table->dropColumn('return_status');
            });
        }
    }

    /** @param array<int> $invoiceIds */
    private function markSettledByReturnsOnly(array $invoiceIds): void
    {
        $received = DB::table('inv_customer_receipt_allocations as a')
            ->join('inv_customer_receipts as r', 'r.id', '=', 'a.receipt_id')
            ->whereIn('a.reference_id', $invoiceIds)
            ->where('a.reference_type', 'invoice')
            ->where('r.status', 'confirmed')
            ->groupBy('a.reference_id')
            ->selectRaw('a.reference_id, SUM(a.receipt_amount + a.discount) as received')
            ->pluck('received', 'reference_id');

        $credited = DB::table('inv_customer_returns')
            ->whereIn('invoice_id', $invoiceIds)
            ->where('status', 'confirmed')
            ->whereNull('deleted_at')
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(applied_to_invoice) as returned')
            ->pluck('returned', 'invoice_id');

        $paid = DB::table('inv_invoices')
            ->whereIn('id', $invoiceIds)
            ->where('status', 'paid')
            ->pluck('grand_total', 'id');

        $returnedOnly = $paid->filter(fn ($grandTotal, $id) => (float) ($received[$id] ?? 0) < 0.01
            && (float) $grandTotal - (float) ($credited[$id] ?? 0) <= 0.01)
            ->keys()
            ->all();

        if (! empty($returnedOnly)) {
            DB::table('inv_invoices')
                ->whereIn('id', $returnedOnly)
                ->update(['status' => 'returned', 'paid_at' => null]);
        }
    }
};
