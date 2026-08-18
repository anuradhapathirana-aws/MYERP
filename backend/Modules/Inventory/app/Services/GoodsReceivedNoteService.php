<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\GoodsReceivedNoteData;
use Modules\Inventory\Enums\BatchStatus;
use Modules\Inventory\Enums\GrnStatus;
use Modules\Inventory\Enums\PurchaseOrderStatus;
use Modules\Inventory\Models\Batch;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GoodsReceivedNoteItem;
use Modules\Inventory\Models\GrnItemBatch;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductLocationStore;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\PurchaseOrderItem;
use Modules\Inventory\Models\StockReferenceType;
use Modules\Inventory\Models\StockTransaction;
use Modules\Inventory\Support\Quantity;

class GoodsReceivedNoteService
{
    public function __construct(
        private readonly ProductPricingService $pricing,
        private readonly UnitConversionService $units,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = GoodsReceivedNote::with(['purchaseOrder', 'supplier', 'store'])
            ->orderByDesc('grn_date')
            ->orderByDesc('id');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term): void {
                $q->where('grn_no', 'like', $term)
                  ->orWhereHas('purchaseOrder', fn ($po) => $po->where('po_no', 'like', $term));
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['store_id'])) {
            $query->where('store_id', (int) $filters['store_id']);
        }

        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('grn_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('grn_date', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    public function find(int $id): GoodsReceivedNote
    {
        return GoodsReceivedNote::with([
            'items.product.baseUnit',
            'items.unit',
            'items.attribute',
            'items.poItem',
            'items.batchAssignments.batch',
            'items.pieces',
            'purchaseOrder.items.product.baseUnit',
            'supplier',
            'store',
            'location',
        ])->findOrFail($id);
    }

    /**
     * Return outstanding items for a single PO.
     * @return array<array<string, mixed>>
     */
    public function getPoOutstandingItems(int $poId): array
    {
        $po = PurchaseOrder::with(['items.product.baseUnit', 'items.unit', 'items.attribute', 'supplier'])->findOrFail($poId);

        if (!$po->status->canReceiveGoods()) {
            abort(422, 'Purchase order must be confirmed before creating a GRN.');
        }

        return $po->items
            ->filter(fn (PurchaseOrderItem $item) => $item->remaining_qty > 0)
            ->map(fn (PurchaseOrderItem $item) => [
                'po_id'            => $po->id,
                'po_no'            => $po->po_no,
                'po_item_id'       => $item->id,
                'product_id'       => $item->product_id,
                'unit_id'          => $item->unit_id,
                'attribute_id'     => $item->attribute_id,
                'attribute_name'   => $item->attribute?->attribute_name,
                'quantity_ordered'  => (float) $item->quantity_ordered,
                'quantity_received' => (float) $item->quantity_received,
                'remaining_qty'    => $item->remaining_qty,
                'unit_price'       => (float) $item->unit_price,
                'discount'         => (float) $item->discount,
                'tax'              => (float) $item->tax,
                'product'          => [
                    'id'           => $item->product->id,
                    'name'         => $item->product->name,
                    'product_code' => $item->product->product_code,
                    'is_batch'     => $item->product->is_batch,
                    'is_serial'    => $item->product->is_serial,
                    'base_unit_type_id'     => $item->product->base_unit_type_id,
                    'base_unit_category_id' => $item->product->baseUnit?->unit_category_id,
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * Return outstanding items for multiple POs in one call.
     * @param array<int> $poIds
     * @return array<array<string, mixed>>
     */
    public function getPoOutstandingItemsForMultiple(array $poIds): array
    {
        if (empty($poIds)) {
            return [];
        }

        $pos = PurchaseOrder::with(['items.product.baseUnit', 'items.unit', 'items.attribute'])
            ->whereIn('id', $poIds)
            ->get();

        $results = [];

        foreach ($pos as $po) {
            if (!$po->status->canReceiveGoods()) {
                continue;
            }

            foreach ($po->items->filter(fn (PurchaseOrderItem $i) => $i->remaining_qty > 0) as $item) {
                $results[] = [
                    'po_id'            => $po->id,
                    'po_no'            => $po->po_no,
                    'po_item_id'       => $item->id,
                    'product_id'       => $item->product_id,
                    'unit_id'          => $item->unit_id,
                    'attribute_id'     => $item->attribute_id,
                    'attribute_name'   => $item->attribute?->attribute_name,
                    'quantity_ordered'  => (float) $item->quantity_ordered,
                    'quantity_received' => (float) $item->quantity_received,
                    'remaining_qty'    => $item->remaining_qty,
                    'unit_price'       => (float) $item->unit_price,
                    'discount'         => (float) $item->discount,
                    'tax'              => (float) $item->tax,
                    'product'          => [
                        'id'           => $item->product->id,
                        'name'         => $item->product->name,
                        'product_code' => $item->product->product_code,
                        'is_batch'     => $item->product->is_batch,
                        'is_serial'    => $item->product->is_serial,
                        'base_unit_type_id'     => $item->product->base_unit_type_id,
                        'base_unit_category_id' => $item->product->baseUnit?->unit_category_id,
                    ],
                ];
            }
        }

        return $results;
    }

    public function nextGrnNo(): string
    {
        return $this->generateGrnNo();
    }

    /** True when no other (non-trashed) GRN already uses the shipping code. */
    public function isShippingCodeAvailable(string $shippingCode, ?int $excludeId = null): bool
    {
        return !GoodsReceivedNote::query()
            ->where('shipping_code', $shippingCode)
            ->when($excludeId !== null, fn ($query) => $query->whereKeyNot($excludeId))
            ->exists();
    }

    /**
     * Return the most-recent unit_price recorded in any GRN for each requested product.
     *
     * @param  int[]  $productIds
     * @return array<int, string>  keyed by product_id
     */
    public function lastProductPrices(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        return GoodsReceivedNoteItem::query()
            ->join('inv_goods_received_notes as g', 'g.id', '=', 'inv_goods_received_note_items.grn_id')
            ->whereIn('inv_goods_received_note_items.product_id', $productIds)
            ->where('inv_goods_received_note_items.unit_price', '>', 0)
            ->orderByDesc('g.grn_date')
            ->orderByDesc('g.id')
            ->select('inv_goods_received_note_items.product_id', 'inv_goods_received_note_items.unit_price')
            ->get()
            ->unique('product_id')
            ->pluck('unit_price', 'product_id')
            ->map(fn ($v) => (string) $v)
            ->all();
    }

    /** @return array{grn_date: ?string, total_amount: float}|null */
    public function lastGrn(): ?array
    {
        $last = GoodsReceivedNote::with([
                'items' => fn ($q) => $q->select('grn_id', 'quantity_received', 'unit_price'),
            ])
            ->where('status', GrnStatus::Confirmed->value)
            ->orderByDesc('grn_date')
            ->orderByDesc('id')
            ->first(['id', 'grn_date']);

        if (!$last) {
            return null;
        }

        $total = $last->items->sum(
            fn (GoodsReceivedNoteItem $item) => (float) $item->quantity_received * (float) $item->unit_price
        );

        return [
            'grn_date'     => $last->grn_date?->toDateString(),
            'total_amount' => $total,
        ];
    }

    public function create(GoodsReceivedNoteData $data): GoodsReceivedNote
    {
        return DB::transaction(function () use ($data): GoodsReceivedNote {
            // Derive supplier from explicit field or from PO
            $supplierId = $data->supplierId;
            if (!$supplierId && $data->poId) {
                $po         = PurchaseOrder::findOrFail($data->poId);
                $supplierId = $po->supplier_id;
            }

            $grn = GoodsReceivedNote::create([
                'grn_no'           => $this->generateGrnNo(),
                'reference_no'     => $data->referenceNo,
                'shipping_code'    => $data->shippingCode,
                'po_id'            => $data->poId,
                'supplier_id'      => $supplierId,
                'grn_date'         => $data->grnDate,
                'transaction_date' => $data->transactionDate,
                'store_id'         => $data->storeId,
                'location_id'      => $data->locationId,
                'status'           => GrnStatus::Draft,
                'remarks'          => $data->remarks,
                'payment_terms'    => $data->paymentTerms,
                'attachments'      => $data->attachments,
                'total_amount'     => 0,
                'received_by'      => auth()->id(),
            ]);

            $this->syncItems($grn, $data->items);
            $this->recalculateTotal($grn);

            return $grn->load(['items.product.baseUnit', 'items.unit', 'items.attribute', 'purchaseOrder', 'supplier', 'store', 'location']);
        });
    }

    public function update(GoodsReceivedNote $grn, GoodsReceivedNoteData $data): GoodsReceivedNote
    {
        if ($grn->status !== GrnStatus::Draft) {
            abort(422, 'Only draft GRNs can be edited.');
        }

        return DB::transaction(function () use ($grn, $data): GoodsReceivedNote {
            $grn->update([
                'grn_date'         => $data->grnDate,
                'transaction_date' => $data->transactionDate,
                'reference_no'     => $data->referenceNo,
                'shipping_code'    => $data->shippingCode,
                'supplier_id'      => $data->supplierId ?? $grn->supplier_id,
                'store_id'         => $data->storeId,
                'location_id'      => $data->locationId,
                'remarks'          => $data->remarks,
                'payment_terms'    => $data->paymentTerms,
                'attachments'      => $data->attachments,
            ]);

            $this->syncItems($grn, $data->items);
            $this->recalculateTotal($grn);

            return $grn->load(['items.product.baseUnit', 'items.unit', 'items.attribute', 'purchaseOrder', 'supplier', 'store', 'location']);
        });
    }

    /**
     * Confirm GRN: post stock into inv_product_location_stores and inv_stock_transactions.
     * Stock posting is skipped when no store is assigned (items still update PO quantities).
     */
    public function confirm(GoodsReceivedNote $grn): GoodsReceivedNote
    {
        return DB::transaction(function () use ($grn): GoodsReceivedNote {
            $grn = GoodsReceivedNote::whereKey($grn->id)->lockForUpdate()->firstOrFail();

            if ($grn->status !== GrnStatus::Draft) {
                abort(422, 'Only draft GRNs can be confirmed.');
            }

            $grn->load(['items.product.baseUnit', 'items.batchAssignments.batch', 'items.pieces']);

            // Every line must be received as rolls — they become the QR-labelled
            // pieces that sales orders allocate. Catches legacy drafts saved
            // before rolls became mandatory.
            foreach ($grn->items as $item) {
                if ($item->pieces->isEmpty()) {
                    abort(422, "Add rolls for {$item->product?->name} before confirming — every GRN line must be received as rolls.");
                }
            }

            // Collect affected PO IDs from items (supports multi-PO GRNs)
            $poItemIds     = $grn->items->pluck('po_item_id')->filter()->unique()->values();
            $affectedPoIds = PurchaseOrderItem::whereIn('id', $poItemIds)
                ->pluck('po_id')
                ->unique()
                ->values();

            foreach ($grn->items as $itemSeq => $item) {
                $batchAssignments        = $item->batchAssignments;
                $stockTransactionIdByBatch = [];
                $nonBatchStockTransactionId = null;

                // The line was received in the supplier's UOM (say Kg); stock only
                // ever speaks the product's stocking UOM (say g). Everything below
                // this point posts base-unit quantities.
                $line       = $this->units->toBase($item->product, $item->unit_id, (float) $item->quantity_received);
                $baseUnitId = $line['base_unit_id'];

                // The price follows the quantity into base units: 250 per Kg is 0.25 per g.
                // Without this, qty_in × unit_price (which is how the valuation report and
                // the WAC subquery compute stock value) would be overstated 1000×.
                $basePrice = $this->units->priceToBase((float) $item->unit_price, $line['factor']);

                // Roll weights were already converted to base units when the draft was saved,
                // using the rate in force at that moment. Should that rate have been edited
                // since, this puts them back in step with the quantity we are about to post.
                $draftFactor = (float) $item->conversion_factor;
                $rescale     = $draftFactor > 0 ? $line['factor'] / $draftFactor : 1.0;

                $item->update([
                    'conversion_factor' => $line['factor'],
                    'base_quantity'     => $line['qty'],
                ]);

                if ($batchAssignments->isNotEmpty()) {
                    // Batch-tracked product: one stock transaction + batch record per assignment
                    foreach ($batchAssignments as $assignment) {
                        $batch     = $assignment->batch;
                        $batchBase = $this->units->toBase($item->product, $item->unit_id, (float) $assignment->quantity);

                        // Create stock transaction per batch slice.
                        // reference_id names the GRN, not this line — so colour has to
                        // travel on the row itself, or a ledger row of a GRN that received
                        // two colours of one product could never be told apart.
                        $stockTransaction = StockTransaction::create([
                            'transaction_date' => now(),
                            'reference_type'   => StockReferenceType::CODE_GRN,
                            'reference_id'     => $grn->id,
                            'product_id'       => $item->product_id,
                            'attribute_id'     => $item->attribute_id,
                            'store_id'         => $grn->store_id,
                            'location_id'      => $grn->location_id,
                            'batch_no'         => $batch->batch_no,
                            'batch_id'         => $batch->id,
                            'expiry_date'      => $batch->expiry_date,
                            'qty_in'           => $batchBase['qty'],
                            'qty_out'          => 0,
                            'unit_id'          => $baseUnitId,
                            // The balance moves in base units; the document said 10 Roll.
                            'entered_unit_id'  => $item->unit_id,
                            'entered_qty'      => (float) $assignment->quantity,
                            'unit_price'       => $basePrice,
                            'created_by'       => auth()->id(),
                        ]);
                        $stockTransactionIdByBatch[$batch->id] = $stockTransaction->id;

                        // Batch quantities were already converted at draft time (see syncItems)
                        $batch->update(['current_qty' => $batch->initial_qty]);

                        // Update denormalized stock balance per batch quantity
                        $pivot = ProductLocationStore::firstOrCreate(
                            [
                                'product_id'  => $item->product_id,
                                'store_id'    => $grn->store_id,
                                'location_id' => $grn->location_id,
                            ],
                            ['current_stock' => 0]
                        );
                        $pivot->increment('current_stock', $batchBase['qty']);
                    }
                } else {
                    // Non-batch product: single stock transaction as before
                    $stockTransaction = StockTransaction::create([
                        'transaction_date' => now(),
                        'reference_type'   => StockReferenceType::CODE_GRN,
                        'reference_id'     => $grn->id,
                        'product_id'       => $item->product_id,
                        'attribute_id'     => $item->attribute_id,
                        'store_id'         => $grn->store_id,
                        'location_id'      => $grn->location_id,
                        'batch_no'         => $item->batch_no,
                        'batch_id'         => null,
                        'expiry_date'      => $item->expiry_date,
                        'qty_in'           => $line['qty'],
                        'qty_out'          => 0,
                        'unit_id'          => $baseUnitId,
                        'entered_unit_id'  => $item->unit_id,
                        'entered_qty'      => (float) $item->quantity_received,
                        'unit_price'       => $basePrice,
                        'created_by'       => auth()->id(),
                    ]);
                    $nonBatchStockTransactionId = $stockTransaction->id;

                    $pivot = ProductLocationStore::firstOrCreate(
                        [
                            'product_id'  => $item->product_id,
                            'store_id'    => $grn->store_id,
                            'location_id' => $grn->location_id,
                        ],
                        ['current_stock' => 0]
                    );
                    $pivot->increment('current_stock', $line['qty']);
                }

                $this->sealPiecesForItem($grn, $item, $itemSeq + 1, $stockTransactionIdByBatch, $nonBatchStockTransactionId, $rescale);

                // Update quantity_received on the linked PO item (skip for manual GRN items)
                if ($item->po_item_id) {
                    PurchaseOrderItem::where('id', $item->po_item_id)
                        ->increment('quantity_received', (float) $item->quantity_received);
                }
            }

            // Mirror each line's purchase cost onto the product's price list
            // (last-cost method). Selling prices are never touched — margins
            // stay the user's decision on the Product form.
            //
            // Keyed by the line's own unit: the price list stores a price per unit, and
            // this line's price is per the unit it was purchased in.
            foreach ($grn->items as $item) {
                $this->pricing->syncLastCost(
                    (int) $item->product_id,
                    $item->unit_id !== null ? (int) $item->unit_id : null,
                    (float) $item->unit_price,
                );
            }

            // Mark GRN as confirmed
            $grn->update([
                'status'       => GrnStatus::Confirmed,
                'confirmed_at' => now(),
            ]);

            // Recompute status for all affected POs
            foreach ($affectedPoIds as $poId) {
                $this->syncPoStatusAfterGrn($poId);
            }

            return $grn->fresh(['items.product.baseUnit', 'items.unit', 'items.attribute', 'purchaseOrder', 'supplier', 'store', 'location']);
        });
    }

    public function delete(GoodsReceivedNote $grn): void
    {
        if ($grn->status !== GrnStatus::Draft) {
            abort(422, 'Only draft GRNs can be deleted.');
        }

        $grn->delete();
    }

    private function syncPoStatusAfterGrn(int $poId): void
    {
        $po = PurchaseOrder::with('items')->find($poId);
        if (!$po) {
            return;
        }

        $hasOutstanding = $po->items->some(
            fn (PurchaseOrderItem $item) => $item->remaining_qty > 0
        );

        $newStatus = $hasOutstanding
            ? PurchaseOrderStatus::PartiallyReceived
            : PurchaseOrderStatus::Completed;

        $po->update(['status' => $newStatus]);
    }

    private function generateGrnNo(): string
    {
        $year   = now()->year;
        $prefix = "GRN-{$year}-";

        $last = GoodsReceivedNote::withTrashed()
            ->where('grn_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('grn_no');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Seal the piece rows already created for this item at draft time (see syncItems()) —
     * assigns the final piece_code, flips status to in_stock, and links each piece to the
     * stock transaction that posted its quantity. Runs only at confirmation time, since GRN
     * items become immutable once confirmed (piece_code depends on a stable item sequence).
     *
     * @param  array<int, int> $stockTransactionIdByBatch keyed by batch_id
     * @param  float $rescale corrects roll weights when the Kg→g rate was edited between the
     *         draft save and this confirmation, so that Σ roll weight still equals base_quantity
     */
    private function sealPiecesForItem(
        GoodsReceivedNote $grn,
        GoodsReceivedNoteItem $item,
        int $itemSeq,
        array $stockTransactionIdByBatch,
        ?int $nonBatchStockTransactionId,
        float $rescale,
    ): void {
        foreach ($item->pieces as $piece) {
            $stockTransactionId = $piece->batch_id
                ? ($stockTransactionIdByBatch[$piece->batch_id] ?? null)
                : $nonBatchStockTransactionId;

            $piece->update([
                'piece_code'           => sprintf('%s-I%03d-P%03d', $grn->grn_no, $itemSeq, $piece->piece_no),
                'status'               => 'in_stock',
                'stock_transaction_id' => $stockTransactionId,
                'weight'               => $piece->weight !== null && $rescale !== 1.0
                    ? Quantity::round((float) $piece->weight * $rescale)
                    : $piece->weight,
            ]);
        }
    }

    /**
     * @param array<array{po_item_id:int, product_id:int, unit_id:?int, quantity_received:float, rolls:?array, unit_price:float, discount:?float, tax:?float, batch_no:?string, expiry_date:?string, batches:?array}> $items
     */
    private function syncItems(GoodsReceivedNote $grn, array $items): void
    {
        // Delete old bridge records first, then items
        $oldItemIds = $grn->items()->pluck('id');
        if ($oldItemIds->isNotEmpty()) {
            GrnItemBatch::whereIn('grn_item_id', $oldItemIds)->delete();
        }
        // By grn_id (not item ids) so pieces orphaned from earlier item syncs can't
        // linger and collide on piece_code when the GRN is confirmed.
        GrnItemPiece::where('grn_id', $grn->id)->delete();
        $grn->items()->delete();

        $poItem = PurchaseOrderItem::whereIn(
            'id',
            collect($items)->pluck('po_item_id')->filter()->unique()->values()
        )->get()->keyBy('id');

        $validItems = collect($items)
            ->filter(fn (array $row) => !empty($row['product_id']) && ($row['quantity_received'] ?? 0) > 0)
            ->values();

        $products = Product::whereIn('id', $validItems->pluck('product_id')->unique())
            ->get()
            ->keyBy('id');

        foreach ($validItems as $row) {
            $product = $products[(int) $row['product_id']];
            $hasPoItem   = !empty($row['po_item_id']);
            $ordered     = $hasPoItem ? (float) ($poItem[$row['po_item_id']]?->quantity_ordered ?? 0) : 0;
            $qtyRcv      = (float) $row['quantity_received'];
            $rolls       = $row['rolls'] ?? [];
            $noOfPieces  = count($rolls);
            $unitId      = !empty($row['unit_id']) ? (int) $row['unit_id'] : null;
            // Priced per the line's receiving UOM — straight off the supplier invoice
            // (250 per Kg). It is rebased to the stocking UOM only when it reaches the
            // stock ledger; see confirm().
            $unitPrice   = (float) ($row['unit_price'] ?? 0);
            $discountPct = (float) ($row['discount'] ?? 0);
            $taxPct      = (float) ($row['tax'] ?? 0);

            // Resolved at draft time so an unconvertible UOM is rejected while the user
            // is still on the form, not at confirm. Re-resolved on confirm — that is the
            // value that actually posts.
            $line = $this->units->toBase($product, $unitId, $qtyRcv);

            // Quantity and price are both in the line's UOM, so this is the invoice value.
            $gross     = $qtyRcv * $unitPrice;
            $discAmt   = $gross * ($discountPct / 100);
            $taxAmt    = $gross * ($taxPct / 100);
            $lineTotal = $gross - $discAmt + $taxAmt;

            $batches   = $row['batches'] ?? [];
            // Derive first batch's no/expiry for the denormalized columns (backward compat display)
            $firstBatch    = !empty($batches) ? $batches[0] : null;
            $batchNoSnap   = $firstBatch['batch_no']    ?? ($row['batch_no']    ?? null);
            $expirySnap    = $firstBatch['expiry_date'] ?? ($row['expiry_date'] ?? null);

            // Color/attribute: for PO-linked rows this is a reference copied from the linked
            // PO item (no independent input on the GRN form); manual (non-PO) rows have no PO
            // item to inherit from, so the user picks it directly on the GRN form instead.
            $attributeId = $hasPoItem
                ? ($poItem[$row['po_item_id']]?->attribute_id ?? null)
                : (!empty($row['attribute_id']) ? (int) $row['attribute_id'] : null);

            $grnItem = GoodsReceivedNoteItem::create([
                'grn_id'            => $grn->id,
                'po_item_id'        => $hasPoItem ? (int) $row['po_item_id'] : null,
                'product_id'        => (int) $row['product_id'],
                'unit_id'           => $unitId,
                'attribute_id'      => $attributeId,
                'quantity_ordered'  => $ordered,
                'quantity_received' => $qtyRcv,
                'conversion_factor' => $line['factor'],
                'base_quantity'     => $line['qty'],
                'no_of_pieces'      => $noOfPieces,
                'unit_price'        => $unitPrice,
                'discount'          => $discountPct,
                'tax'               => $taxPct,
                'line_total'        => $lineTotal,
                'batch_no'          => $batchNoSnap,
                'expiry_date'       => !empty($expirySnap) ? $expirySnap : null,
            ]);

            // Create batch master + bridge records for batch-tracked products
            if (!empty($batches)) {
                foreach ($batches as $batchRow) {
                    $batchQty = (float) ($batchRow['quantity'] ?? 0);
                    if ($batchQty <= 0) {
                        continue;
                    }

                    // Batch master quantities are a stock balance, so they live in the
                    // product's stocking UOM — and its unit_cost must follow, or
                    // current_qty × unit_cost stops being the batch's value. The bridge
                    // row below keeps the quantity and price the user typed on the document.
                    $batchBaseQty   = $this->units->toBase($product, $unitId, $batchQty)['qty'];
                    $batchBaseCost  = $this->units->priceToBase($unitPrice, $line['factor']);

                    $batch = Batch::firstOrCreate(
                        [
                            'product_id' => (int) $row['product_id'],
                            'batch_no'   => trim((string) $batchRow['batch_no']),
                        ],
                        [
                            'supplier_id'       => $grn->supplier_id,
                            'supplier_batch_no' => $batchRow['supplier_batch_no'] ?? null,
                            'mfg_date'          => !empty($batchRow['mfg_date'])     ? $batchRow['mfg_date']     : null,
                            'expiry_date'       => !empty($batchRow['expiry_date'])  ? $batchRow['expiry_date']  : null,
                            'received_date'     => $grn->grn_date,
                            'initial_qty'       => $batchBaseQty,
                            'current_qty'       => $batchBaseQty,
                            'unit_cost'         => $batchBaseCost,
                            'status'            => BatchStatus::from($batchRow['status'] ?? 'active'),
                            'country_of_origin' => $batchRow['country_of_origin'] ?? null,
                            'notes'             => $batchRow['notes']             ?? null,
                            'created_by'        => auth()->id(),
                        ]
                    );

                    GrnItemBatch::create([
                        'grn_item_id' => $grnItem->id,
                        'batch_id'    => $batch->id,
                        'quantity'    => $batchQty,
                        'unit_cost'   => $unitPrice,
                    ]);
                }
            }

            // Create piece rows for each roll captured at draft time (weight + roll_no).
            // These stay "unsealed" (piece_code null, status draft) until GRN confirmation,
            // since GRN items get deleted and recreated on every draft save/edit.
            //
            // The user types roll weights in the line's UOM (1.5 Kg), but a roll IS stock —
            // sales allocate against these weights and DeliveryOrderService sums them to
            // decrement current_stock. So they are stored in the product's stocking UOM
            // (1,500 g), same as every other balance. GoodsReceivedNoteItemResource turns
            // them back into the line's UOM for the roll editor.
            if (!empty($rolls)) {
                $batchIdsForRolls = $this->distributeBatchIdsAcrossRolls($grnItem, count($rolls));

                foreach ($rolls as $i => $rollRow) {
                    GrnItemPiece::create([
                        'grn_item_id' => $grnItem->id,
                        'grn_id'      => $grn->id,
                        'product_id'  => (int) $row['product_id'],
                        'batch_id'    => $batchIdsForRolls[$i] ?? null,
                        'store_id'    => $grn->store_id,
                        'location_id' => $grn->location_id,
                        'piece_no'    => $i + 1,
                        'weight'      => Quantity::round((float) $rollRow['weight'] * $line['factor']),
                        'roll_no'     => $rollRow['roll_no'],
                        'piece_code'  => null,
                        'status'      => 'draft',
                        'created_by'  => auth()->id(),
                    ]);
                }
            }
        }
    }

    /**
     * Distribute rolls proportionally across a GRN item's batch assignments by quantity
     * share, so each roll/piece can be linked to the batch it physically belongs to.
     *
     * @return array<int, ?int> batch_id per roll index (0..$rollCount-1), or all null if unbatched
     */
    private function distributeBatchIdsAcrossRolls(GoodsReceivedNoteItem $grnItem, int $rollCount): array
    {
        $assignments = $grnItem->batchAssignments()->get();
        if ($assignments->isEmpty() || $rollCount === 0) {
            return array_fill(0, $rollCount, null);
        }

        $totalQty  = (float) $grnItem->quantity_received;
        $allocated = 0;
        $count     = $assignments->count();
        $result    = [];

        foreach ($assignments as $index => $assignment) {
            $isLast = $index === $count - 1;
            $share  = $isLast
                ? $rollCount - $allocated
                : (int) round(((float) $assignment->quantity / $totalQty) * $rollCount);

            for ($i = 0; $i < $share; $i++) {
                $result[] = (int) $assignment->batch_id;
            }
            $allocated += $share;
        }

        return $result;
    }

    private function recalculateTotal(GoodsReceivedNote $grn): void
    {
        $grn->refresh();
        $total = $grn->items()->sum('line_total');
        $grn->update(['total_amount' => $total]);
    }
}
