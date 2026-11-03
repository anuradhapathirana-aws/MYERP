<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\SupplierPaymentStatus;
use Modules\Inventory\Enums\SupplierReturnStatus;
use Modules\Inventory\Models\SupplierCreditNote;

/**
 * The single definition of what we still owe a supplier on a GRN:
 *
 *   outstanding = total_amount
 *               − SUM(confirmed payment allocations' payment_amount + discount)
 *               − SUM(confirmed supplier returns' applied_to_grn)
 *
 * A payment discount is a permanent write-off, so it settles the GRN exactly like cash.
 * A return reduces the GRN only up to what was still owed when it was confirmed
 * (applied_to_grn) — anything above that was already paid and lives on as an open
 * supplier_return credit note instead, so it is never counted twice.
 *
 * Supplier payments and supplier returns both read it from here.
 */
class GrnBalanceService
{
    /**
     * Amount settled per GRN by confirmed supplier payments.
     *
     * @param  array<int> $grnIds
     * @return Collection<int, float> GRN id => paid
     */
    public function paidFor(array $grnIds): Collection
    {
        if (empty($grnIds)) {
            return collect();
        }

        return DB::table('inv_supplier_payment_allocations as a')
            ->join('inv_supplier_payments as p', 'p.id', '=', 'a.payment_id')
            ->whereIn('a.reference_id', $grnIds)
            ->where('a.reference_type', 'grn')
            ->where('p.status', SupplierPaymentStatus::Confirmed->value)
            ->groupBy('a.reference_id')
            ->select('a.reference_id', DB::raw('SUM(a.payment_amount + a.discount) as paid'))
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->reference_id => (float) $row->paid]);
    }

    /**
     * GRN balance cleared by confirmed supplier returns.
     *
     * @param  array<int> $grnIds
     * @return Collection<int, float> GRN id => returned
     */
    public function returnedFor(array $grnIds): Collection
    {
        if (empty($grnIds)) {
            return collect();
        }

        return DB::table('inv_supplier_returns')
            ->whereIn('grn_id', $grnIds)
            ->where('status', SupplierReturnStatus::Confirmed->value)
            ->whereNull('deleted_at')
            ->groupBy('grn_id')
            ->select('grn_id', DB::raw('SUM(applied_to_grn) as returned'))
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->grn_id => (float) $row->returned]);
    }

    /**
     * Outstanding per GRN, keyed by id.
     *
     * @param  array<int, float|string> $totals GRN id => total_amount
     * @return array<int, float>
     */
    public function outstandingFor(array $totals): array
    {
        $ids      = array_map('intval', array_keys($totals));
        $paid     = $this->paidFor($ids);
        $returned = $this->returnedFor($ids);

        $result = [];

        foreach ($totals as $grnId => $total) {
            $result[(int) $grnId] = (float) $total
                - ($paid[$grnId] ?? 0.0)
                - ($returned[$grnId] ?? 0.0);
        }

        return $result;
    }

    /** Outstanding for a single GRN. */
    public function outstanding(int $grnId): float
    {
        $total = DB::table('inv_goods_received_notes')->where('id', $grnId)->value('total_amount');

        if ($total === null) {
            return 0.0;
        }

        return $this->outstandingFor([$grnId => $total])[$grnId];
    }

    /**
     * Atomically generate the next supplier credit note number (must be called inside a
     * DB transaction). One series for every note — advance, over-payment and return.
     */
    public function generateCreditNoteNo(): string
    {
        $prefix = 'CN-';

        $last = SupplierCreditNote::where('credit_note_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('credit_note_no');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
