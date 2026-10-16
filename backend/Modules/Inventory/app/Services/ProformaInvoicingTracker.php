<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\InvoiceStatus;
use Modules\Inventory\Enums\ProformaInvoiceStatus;
use Modules\Inventory\Enums\SalesOrderStatus;
use Modules\Inventory\Models\ProformaInvoice;
use Modules\Inventory\Models\ProformaInvoiceItem;

/**
 * The link between a proforma and the documents that fulfil it. The link is per
 * LINE: an SO line remembers the proforma line it was recalled from
 * (inv_sales_order_items.proforma_item_id), and a DO invoice line the proforma
 * line it billed (inv_invoice_items.proforma_item_id). One proforma can therefore
 * feed several SOs, and a roll-split SO line still bills the quoted price.
 *
 * Kept apart from InvoiceService and ProformaInvoiceService so both can use it
 * without depending on each other.
 */
class ProformaInvoicingTracker
{
    private const EPSILON = 0.0001;

    /**
     * Proforma lines behind the given SO lines, keyed by SO line id — only those
     * whose proforma is issued or further (a draft is not a quote yet).
     *
     * @param  iterable<int> $soItemIds
     * @return Collection<int, ProformaInvoiceItem>
     */
    public function proformaItemsForSoItems(iterable $soItemIds): Collection
    {
        $ids = collect($soItemIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $links = DB::table('inv_sales_order_items')
            ->whereIn('id', $ids)
            ->whereNotNull('proforma_item_id')
            ->pluck('proforma_item_id', 'id');

        if ($links->isEmpty()) {
            return collect();
        }

        $items = ProformaInvoiceItem::with('proforma')
            ->whereIn('id', $links->values()->unique())
            ->whereHas('proforma', fn ($q) => $q->whereIn('status', ProformaInvoiceStatus::linkable()))
            ->get()
            ->keyBy('id');

        return $links
            ->map(fn ($proformaItemId) => $items->get((int) $proformaItemId))
            ->filter();
    }

    /**
     * Re-derives Issued / PartiallyInvoiced / Converted from the quantity billed on
     * the non-cancelled invoices linked to each proforma line. Runs after an invoice
     * is created, cancelled or deleted, so a cancelled invoice rolls the status back.
     */
    public function refreshInvoicingStatus(int $proformaId): void
    {
        $proforma = ProformaInvoice::with('items')->whereKey($proformaId)->lockForUpdate()->first();

        if (!$proforma || !in_array($proforma->status->value, ProformaInvoiceStatus::linkable(), true)) {
            return;
        }

        $invoiced = $this->invoicedQuantities($proforma->id);

        $anyInvoiced = $invoiced->sum() > self::EPSILON;
        $allInvoiced = $proforma->items->isNotEmpty() && $proforma->items->every(
            fn ($item) => (float) ($invoiced[$item->id] ?? 0) >= (float) $item->quantity - self::EPSILON,
        );

        $status = match (true) {
            $allInvoiced => ProformaInvoiceStatus::Converted,
            $anyInvoiced => ProformaInvoiceStatus::PartiallyInvoiced,
            default      => ProformaInvoiceStatus::Issued,
        };

        if ($status === $proforma->status) {
            return;
        }

        $proforma->update([
            'status'       => $status,
            'converted_at' => $status === ProformaInvoiceStatus::Converted ? now() : null,
        ]);
    }

    /** Refresh every proforma behind the given proforma lines (an invoice may span several). */
    public function refreshForProformaItems(iterable $proformaItemIds): void
    {
        $ids = collect($proformaItemIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        ProformaInvoiceItem::whereIn('id', $ids)
            ->distinct()
            ->pluck('proforma_id')
            ->each(fn ($proformaId) => $this->refreshInvoicingStatus((int) $proformaId));
    }

    /**
     * Quantity billed per proforma line on non-cancelled invoices.
     *
     * @return Collection<int, float> keyed by proforma_item_id
     */
    public function invoicedQuantities(int $proformaId): Collection
    {
        return DB::table('inv_invoice_items as ii')
            ->join('inv_invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->join('inv_proforma_invoice_items as pi', 'pi.id', '=', 'ii.proforma_item_id')
            ->where('pi.proforma_id', $proformaId)
            ->where('i.status', '!=', InvoiceStatus::Cancelled->value)
            ->whereNull('i.deleted_at')
            ->groupBy('ii.proforma_item_id')
            ->selectRaw('ii.proforma_item_id, SUM(ii.quantity) as qty')
            ->pluck('qty', 'proforma_item_id')
            ->map(fn ($qty) => (float) $qty);
    }

    /**
     * Quantity already recalled into (non-cancelled) sales orders per proforma line.
     *
     * @return Collection<int, float> keyed by proforma_item_id
     */
    public function recalledQuantities(int $proformaId): Collection
    {
        return DB::table('inv_sales_order_items as si')
            ->join('inv_sales_orders as so', 'so.id', '=', 'si.so_id')
            ->join('inv_proforma_invoice_items as pi', 'pi.id', '=', 'si.proforma_item_id')
            ->where('pi.proforma_id', $proformaId)
            ->where('so.status', '!=', SalesOrderStatus::Cancelled->value)
            ->whereNull('so.deleted_at')
            ->groupBy('si.proforma_item_id')
            ->selectRaw('si.proforma_item_id, SUM(si.quantity) as qty')
            ->pluck('qty', 'proforma_item_id')
            ->map(fn ($qty) => (float) $qty);
    }

    /**
     * Sales orders (non-cancelled) holding lines recalled from this proforma.
     *
     * @return Collection<int, object{id:int, so_no:string, status:string, order_date:?string}>
     */
    public function linkedSalesOrders(int $proformaId): Collection
    {
        return DB::table('inv_sales_orders as so')
            ->join('inv_sales_order_items as si', 'si.so_id', '=', 'so.id')
            ->join('inv_proforma_invoice_items as pi', 'pi.id', '=', 'si.proforma_item_id')
            ->where('pi.proforma_id', $proformaId)
            ->whereNull('so.deleted_at')
            ->groupBy('so.id', 'so.so_no', 'so.status', 'so.order_date')
            ->orderBy('so.id')
            ->get(['so.id', 'so.so_no', 'so.status', 'so.order_date']);
    }
}
