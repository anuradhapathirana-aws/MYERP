<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\StockReconciliationData;
use Modules\Inventory\Enums\StockReconciliationStatus;
use Modules\Inventory\Models\GoodsReceivedNoteItem;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductLocationStore;
use Modules\Inventory\Models\PurchaseOrderItem;
use Modules\Inventory\Models\StockReconciliation;
use Modules\Inventory\Models\StockReconciliationPiece;
use Modules\Inventory\Models\StockReferenceType;
use Modules\Inventory\Models\StockTransaction;

/**
 * Corrective stock entries, raised as Draft, submitted, then approved (only by a user
 * holding approve_stock_reconciliations — admin or super_admin) before they touch the
 * ledger. Built for cases like a GRN that recorded the wrong roll weight after its
 * Costing was already confirmed — since neither GRN nor Costing can be reopened once
 * confirmed (by design, matching every other confirmed document in this app), the
 * correction lives here instead, as a separate, traceable entry, leaving the original
 * documents untouched.
 *
 * Posting (approve()) always recomputes against the LIVE stock balance and the live
 * roll weight, never the stale snapshot taken when the reconciliation was drafted —
 * so the ending balance always lands exactly on the corrected/counted figure, no
 * matter what else moved in the ledger while this was pending approval.
 */
class StockReconciliationService
{
    public function __construct(
        private readonly UnitConversionService $units,
        private readonly ProductPricingService $pricing,
        private readonly GoodsReceivedNoteService $grn,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = StockReconciliation::with(['product', 'store', 'location', 'createdBy', 'approvedBy'])
            ->orderByDesc('id');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term): void {
                $q->where('reconciliation_no', 'like', $term)
                  ->orWhere('reason', 'like', $term);
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['product_id'])) {
            $query->where('product_id', (int) $filters['product_id']);
        }

        if (!empty($filters['store_id'])) {
            $query->where('store_id', (int) $filters['store_id']);
        }

        if (!empty($filters['source_type']) && !empty($filters['source_id'])) {
            $query->where('source_type', $filters['source_type'])
                  ->where('source_id', (int) $filters['source_id']);
        }

        return $query->paginate($perPage);
    }

    public function find(int $id): StockReconciliation
    {
        return StockReconciliation::with([
            'product.baseUnit', 'attribute', 'store', 'location', 'batch', 'unit',
            'createdBy', 'approvedBy',
            'pieces.piece.grn', 'pieces.piece.grnItem.attribute',
        ])->findOrFail($id);
    }

    public function create(StockReconciliationData $data): StockReconciliation
    {
        return DB::transaction(function () use ($data): StockReconciliation {
            $product = Product::findOrFail($data->productId);

            $systemQtyBase = $this->liveStockBase($data->productId, $data->storeId, $data->locationId);

            [$countedQtyBase, $varianceQtyBase, $pieceRows, $attributeId] = $this->computeCorrection($product, $data, $systemQtyBase);

            $reconciliation = StockReconciliation::create([
                'reconciliation_no' => $this->generateReconciliationNo(),
                'product_id'        => $data->productId,
                'attribute_id'      => $attributeId,
                'store_id'          => $data->storeId,
                'location_id'       => $data->locationId,
                'batch_id'          => $data->batchId,
                'unit_id'           => $data->unitId,
                'system_qty_base'   => $systemQtyBase,
                'counted_qty_base'  => $countedQtyBase,
                'variance_qty_base' => $varianceQtyBase,
                'source_type'       => $data->sourceType,
                'source_id'         => $data->sourceId,
                'also_adjust_po'    => $data->alsoAdjustPo,
                'reason'            => $data->reason,
                'remarks'           => $data->remarks,
                'status'            => StockReconciliationStatus::Draft,
                'created_by'        => auth()->id(),
            ]);

            $this->syncPieces($reconciliation, $pieceRows);

            return $this->find($reconciliation->id);
        });
    }

    public function update(StockReconciliation $reconciliation, StockReconciliationData $data): StockReconciliation
    {
        if ($reconciliation->status !== StockReconciliationStatus::Draft) {
            abort(422, 'Only draft reconciliations can be edited.');
        }

        return DB::transaction(function () use ($reconciliation, $data): StockReconciliation {
            $product = Product::findOrFail($data->productId);

            $systemQtyBase = $this->liveStockBase($data->productId, $data->storeId, $data->locationId);

            [$countedQtyBase, $varianceQtyBase, $pieceRows, $attributeId] = $this->computeCorrection($product, $data, $systemQtyBase);

            $reconciliation->update([
                'product_id'        => $data->productId,
                'attribute_id'      => $attributeId,
                'store_id'          => $data->storeId,
                'location_id'       => $data->locationId,
                'batch_id'          => $data->batchId,
                'unit_id'           => $data->unitId,
                'system_qty_base'   => $systemQtyBase,
                'counted_qty_base'  => $countedQtyBase,
                'variance_qty_base' => $varianceQtyBase,
                'source_type'       => $data->sourceType,
                'source_id'         => $data->sourceId,
                'also_adjust_po'    => $data->alsoAdjustPo,
                'reason'            => $data->reason,
                'remarks'           => $data->remarks,
            ]);

            $this->syncPieces($reconciliation, $pieceRows);

            return $this->find($reconciliation->id);
        });
    }

    public function delete(StockReconciliation $reconciliation): void
    {
        if ($reconciliation->status !== StockReconciliationStatus::Draft) {
            abort(422, 'Only draft reconciliations can be deleted.');
        }

        $reconciliation->delete();
    }

    public function submit(StockReconciliation $reconciliation): StockReconciliation
    {
        if ($reconciliation->status !== StockReconciliationStatus::Draft) {
            abort(422, 'Only draft reconciliations can be submitted for approval.');
        }

        $reconciliation->update([
            'status'       => StockReconciliationStatus::PendingApproval,
            'submitted_at' => now(),
        ]);

        return $this->find($reconciliation->id);
    }

    /**
     * Post the correction: ledger + denormalized balance + roll weight(s) + (optionally)
     * the linked PO's received quantity, all inside one locked transaction. Restricted
     * to approve_stock_reconciliations (admin/super_admin) at the route — the creator
     * may approve their own reconciliation.
     */
    public function approve(StockReconciliation $reconciliation): StockReconciliation
    {
        return DB::transaction(function () use ($reconciliation): StockReconciliation {
            $locked = StockReconciliation::whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== StockReconciliationStatus::PendingApproval) {
                abort(422, 'Only reconciliations pending approval can be approved.');
            }

            $locked->load('pieces');

            $pivot = ProductLocationStore::firstOrCreate(
                [
                    'product_id'  => $locked->product_id,
                    'store_id'    => $locked->store_id,
                    'location_id' => $locked->location_id,
                ],
                ['current_stock' => 0],
            );
            $pivot = ProductLocationStore::whereKey($pivot->id)->lockForUpdate()->first();

            $liveSystemQtyBase = (float) $pivot->current_stock;
            $unitPrice         = $this->pricing->lastCostFor((int) $locked->product_id) ?? 0.0;

            if ($locked->pieces->isNotEmpty()) {
                $varianceBase = 0.0;

                foreach ($locked->pieces as $pieceRow) {
                    $piece = GrnItemPiece::whereKey($pieceRow->grn_item_piece_id)->lockForUpdate()->first();

                    abort_if($piece === null, 422, 'One of the rolls in this reconciliation no longer exists.');
                    abort_if(
                        $piece->status !== GrnItemPiece::STATUS_IN_STOCK,
                        422,
                        "Roll {$piece->piece_code} is no longer in stock (status: {$piece->status}) — approve is blocked until this is resolved.",
                    );

                    $varianceBase += (float) $pieceRow->new_weight - (float) $piece->weight;
                }
            } else {
                // The counted figure is an absolute target captured at draft time —
                // always reapplied against the CURRENT live balance, so the ending
                // balance lands exactly on what was counted regardless of any stock
                // movement that happened while this was pending.
                $varianceBase = (float) $locked->counted_qty_base - $liveSystemQtyBase;
            }

            if (abs($varianceBase) > 1e-9) {
                StockTransaction::create([
                    'transaction_date' => now(),
                    'reference_type'   => StockReferenceType::CODE_STOCK_ADJUSTMENT,
                    'reference_id'     => $locked->id,
                    'product_id'       => $locked->product_id,
                    'attribute_id'     => $locked->attribute_id,
                    'store_id'         => $locked->store_id,
                    'location_id'      => $locked->location_id,
                    'batch_id'         => $locked->batch_id,
                    'qty_in'           => $varianceBase > 0 ? $varianceBase : 0,
                    'qty_out'          => $varianceBase < 0 ? abs($varianceBase) : 0,
                    'unit_id'          => $locked->product?->base_unit_type_id,
                    'entered_unit_id'  => $locked->unit_id,
                    'entered_qty'      => abs($varianceBase),
                    'unit_price'       => $unitPrice,
                    'created_by'       => auth()->id(),
                ]);

                $pivot->increment('current_stock', $varianceBase);
            }

            foreach ($locked->pieces as $pieceRow) {
                GrnItemPiece::whereKey($pieceRow->grn_item_piece_id)
                    ->update(['weight' => $pieceRow->new_weight]);
            }

            if ($locked->also_adjust_po && $locked->source_type === 'grn' && $locked->source_id) {
                $this->adjustLinkedPo((int) $locked->source_id, (int) $locked->product_id, $locked->attribute_id, $varianceBase);
            }

            $locked->update([
                'variance_qty_base' => $varianceBase,
                'status'            => StockReconciliationStatus::Approved,
                'approved_by'       => auth()->id(),
                'approved_at'       => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    public function reject(StockReconciliation $reconciliation, string $reason): StockReconciliation
    {
        if ($reconciliation->status !== StockReconciliationStatus::PendingApproval) {
            abort(422, 'Only reconciliations pending approval can be rejected.');
        }

        $reconciliation->update([
            'status'           => StockReconciliationStatus::Rejected,
            'rejection_reason' => $reason,
        ]);

        return $this->find($reconciliation->id);
    }

    /** Preview next document number (lock-free, display only) */
    public function nextReconciliationNo(): string
    {
        return $this->generateReconciliationNo(lock: false);
    }

    /**
     * Rolls of a product that are still in stock, for the "attach a roll to correct"
     * picker — same in_stock filter used by SalesOrderService::availablePieces().
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableRolls(int $productId, ?string $search = null): array
    {
        // A roll carries no colour of its own — it inherits the attribute_id of the
        // GRN line it was received against, same as everywhere else in this app
        // (see SalesOrderService::availablePieces()).
        return GrnItemPiece::with(['grn:id,grn_no', 'grnItem:id,attribute_id', 'grnItem.attribute:id,attribute_name'])
            ->where('product_id', $productId)
            ->where('status', GrnItemPiece::STATUS_IN_STOCK)
            ->whereNotNull('piece_code')
            ->when($search, fn ($q) => $q->where(function ($qq) use ($search): void {
                $qq->where('piece_code', 'like', "%{$search}%")
                   ->orWhere('roll_no', 'like', "%{$search}%");
            }))
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn (GrnItemPiece $p) => [
                'id'           => $p->id,
                'piece_code'   => $p->piece_code,
                'roll_no'      => $p->roll_no,
                'weight'       => (float) $p->weight,
                'attribute_id' => $p->grnItem?->attribute_id,
                'color'        => $p->grnItem?->attribute?->attribute_name,
                'grn_no'       => $p->grn?->grn_no,
                'store_id'     => $p->store_id,
                'location_id'  => $p->location_id,
            ])
            ->values()
            ->all();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function liveStockBase(int $productId, int $storeId, int $locationId): float
    {
        $current = ProductLocationStore::where('product_id', $productId)
            ->where('store_id', $storeId)
            ->where('location_id', $locationId)
            ->value('current_stock');

        return (float) ($current ?? 0.0);
    }

    /**
     * @return array{0: float, 1: float, 2: array<int, array{grn_item_piece_id:int, old_weight:float, new_weight:float}>, 3: ?int}
     */
    private function computeCorrection(Product $product, StockReconciliationData $data, float $systemQtyBase): array
    {
        if (!empty($data->pieces)) {
            $pieceIds = collect($data->pieces)->pluck('grn_item_piece_id')->map('intval')->all();
            $pieces   = GrnItemPiece::with('grnItem:id,attribute_id')->whereIn('id', $pieceIds)->get()->keyBy('id');

            $pieceRows    = [];
            $variance     = 0.0;
            $attributeIds = [];

            foreach ($data->pieces as $row) {
                $piece = $pieces->get((int) $row['grn_item_piece_id']);
                abort_if($piece === null, 422, 'One of the selected rolls could not be found.');
                abort_if(
                    (int) $piece->product_id !== $product->id,
                    422,
                    "Roll {$piece->piece_code} does not belong to the selected product.",
                );
                abort_if(
                    $piece->status !== GrnItemPiece::STATUS_IN_STOCK,
                    422,
                    "Roll {$piece->piece_code} is not currently in stock (status: {$piece->status}) and cannot be corrected.",
                );

                $newWeight = (float) $row['new_weight'];
                $variance += $newWeight - (float) $piece->weight;

                $pieceRows[] = [
                    'grn_item_piece_id' => $piece->id,
                    'old_weight'        => (float) $piece->weight,
                    'new_weight'        => $newWeight,
                ];

                // A roll has no colour of its own — it inherits the attribute_id of the
                // GRN line it came from.
                $attributeIds[$piece->grnItem?->attribute_id ?? 0] = $piece->grnItem?->attribute_id;
            }

            // One reconciliation carries one attribute_id — a mixed-colour selection
            // would have to lie about at least one roll's true colour.
            abort_if(
                count($attributeIds) > 1,
                422,
                'Selected rolls are of more than one colour — correct one colour per reconciliation.',
            );

            $attributeId = $attributeIds === [] ? null : array_values($attributeIds)[0];

            return [$systemQtyBase + $variance, $variance, $pieceRows, $attributeId];
        }

        abort_if($data->countedQty === null, 422, 'Enter the counted/actual quantity, or attach at least one roll to correct.');

        $unitId        = $data->unitId ?? $this->units->baseUnitIdFor($product);
        $countedQtyBase = $this->units->toBase($product, $unitId, (float) $data->countedQty)['qty'];

        return [$countedQtyBase, $countedQtyBase - $systemQtyBase, [], $data->attributeId];
    }

    /**
     * @param array<int, array{grn_item_piece_id:int, old_weight:float, new_weight:float}> $pieceRows
     */
    private function syncPieces(StockReconciliation $reconciliation, array $pieceRows): void
    {
        StockReconciliationPiece::where('reconciliation_id', $reconciliation->id)->delete();

        if (empty($pieceRows)) {
            return;
        }

        $rows = collect($pieceRows)->map(fn (array $row) => [
            'reconciliation_id' => $reconciliation->id,
            'grn_item_piece_id' => $row['grn_item_piece_id'],
            'old_weight'        => $row['old_weight'],
            'new_weight'        => $row['new_weight'],
            'created_at'        => now(),
            'updated_at'        => now(),
        ])->all();

        StockReconciliationPiece::insert($rows);
    }

    /** Mirrors the increment GoodsReceivedNoteService::confirm() applies, in reverse when negative. */
    private function adjustLinkedPo(int $grnId, int $productId, ?int $attributeId, float $deltaBase): void
    {
        $grnItem = GoodsReceivedNoteItem::where('grn_id', $grnId)
            ->where('product_id', $productId)
            ->when($attributeId !== null, fn ($q) => $q->where('attribute_id', $attributeId))
            ->whereNotNull('po_item_id')
            ->first();

        if ($grnItem === null) {
            return;
        }

        // base_quantity is per base UOM; quantity_received is per the GRN line's own
        // receiving unit — convert the delta back using the line's frozen factor.
        $factor = (float) $grnItem->conversion_factor ?: 1.0;
        $deltaInReceivingUnit = $factor > 0 ? $deltaBase / $factor : $deltaBase;

        PurchaseOrderItem::where('id', $grnItem->po_item_id)
            ->increment('quantity_received', $deltaInReceivingUnit);

        $poId = PurchaseOrderItem::where('id', $grnItem->po_item_id)->value('po_id');
        if ($poId) {
            $this->grn->syncPoStatusAfterGrn((int) $poId);
        }
    }

    /** Atomically generate the next reconciliation number (SR-YYYY-NNNN) */
    private function generateReconciliationNo(bool $lock = true): string
    {
        $year   = now()->year;
        $prefix = "SR-{$year}-";

        $query = StockReconciliation::withTrashed()
            ->where('reconciliation_no', 'like', $prefix . '%')
            ->orderByDesc('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $last = $query->value('reconciliation_no');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
