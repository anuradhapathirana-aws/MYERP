<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\InvoiceStatus;
use Modules\Inventory\Enums\ProformaInvoiceStatus;
use Modules\Inventory\Models\ProformaInvoice;

/**
 * The link between delivery-order invoices and the proforma of their sales
 * order: which proforma a new DO invoice auto-links to, and how far that
 * proforma has been invoiced. Kept apart from both services so InvoiceService
 * and ProformaInvoiceService can each use it without depending on each other.
 */
class ProformaInvoicingTracker
{
    private const EPSILON = 0.0001;

    /** The SO's one live, issued proforma a new DO invoice should link to — null when none. */
    public function linkableProformaForSo(int $soId): ?ProformaInvoice
    {
        return ProformaInvoice::with('items')
            ->where('so_id', $soId)
            ->whereIn('status', ProformaInvoiceStatus::linkable())
            ->orderByDesc('id')
            ->first();
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

    /**
     * Quantity billed per proforma line on its linked, non-cancelled invoices.
     *
     * @return \Illuminate\Support\Collection<int, float> keyed by proforma_item_id
     */
    public function invoicedQuantities(int $proformaId): \Illuminate\Support\Collection
    {
        return DB::table('inv_invoice_items as ii')
            ->join('inv_invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->where('i.proforma_id', $proformaId)
            ->where('i.status', '!=', InvoiceStatus::Cancelled->value)
            ->whereNull('i.deleted_at')
            ->whereNotNull('ii.proforma_item_id')
            ->groupBy('ii.proforma_item_id')
            ->selectRaw('ii.proforma_item_id, SUM(ii.quantity) as qty')
            ->pluck('qty', 'proforma_item_id')
            ->map(fn ($qty) => (float) $qty);
    }
}
