<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\DeliveryOrderData;
use Modules\Inventory\Enums\DeliveryOrderStatus;
use Modules\Inventory\Enums\SalesOrderStatus;
use Modules\Inventory\Models\Batch;
use Modules\Inventory\Models\DeliveryOrder;
use Modules\Inventory\Models\DeliveryOrderItem;
use Modules\Inventory\Models\DeliveryOrderPiece;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductLocationStore;
use Modules\Inventory\Models\SalesOrder;
use Modules\Inventory\Models\SalesOrderItem;
use Modules\Inventory\Models\SalesOrderPiece;
use Modules\Inventory\Models\StockReferenceType;
use Modules\Inventory\Models\StockTransaction;
use Modules\Inventory\Models\UnitType;
use Modules\Inventory\Support\Quantity;

/**
 * Delivery Orders execute the physical dispatch of a sales order.
 * Draft DOs have no stock effects; confirm() is the system's first outbound
 * stock writer: it posts 'sales_delivery' qty_out ledger rows, decrements
 * inv_product_location_stores (guarded by Product.allow_minus), decrements
 * Batch.current_qty and flips shipped rolls allocated -> delivered.
 * Confirmed DOs are immutable — reversal is the future customer-return.
 */
class DeliveryOrderService
{
    /** @see Quantity::EPSILON — one tolerance for the whole module, not one per service. */
    private const EPSILON = Quantity::EPSILON;

    public function __construct(
        private readonly UnitConversionService $units,
        private readonly RollService $rolls,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = DeliveryOrder::with(['customer', 'salesOrder'])
            ->withSum('items as total_quantity', 'quantity')
            ->orderByDesc('delivery_date')
            ->orderByDesc('id');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term): void {
                $q->where('do_no', 'like', $term)
                  ->orWhereHas('salesOrder', fn ($s) => $s->where('so_no', 'like', $term))
                  ->orWhereHas('customer', fn ($c) => $c->where('customer_name', 'like', $term));
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // invoiced=0 → only DOs still awaiting a live (non-cancelled) invoice;
        // invoiced=1 → only DOs already billed. Used by the invoice "Recall DO" picker.
        if (isset($filters['invoiced']) && $filters['invoiced'] !== '') {
            $wantInvoiced = filter_var($filters['invoiced'], FILTER_VALIDATE_BOOLEAN);
            $query->{$wantInvoiced ? 'whereHas' : 'whereDoesntHave'}('invoice');
        }

        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if (!empty($filters['so_id'])) {
            $query->where('so_id', (int) $filters['so_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('delivery_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('delivery_date', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    public function find(int $id): DeliveryOrder
    {
        return DeliveryOrder::with([
            'items.product.baseUnit',
            'items.unit',
            'items.attribute',
            'items.pieces.piece.store',
            'items.pieces.piece.location',
            'items.pieces.piece.batch',
            'items.pieces.piece.grn',
            'salesOrder.salesPerson',
            'salesOrder.orderTakenBy',
            'customer',
            'driver',
            'vehicle',
            'store',
            'location',
            'invoice',
        ])->findOrFail($id);
    }

    /** Lock-free preview; the real number is generated inside the create transaction. */
    public function nextDoNo(): string
    {
        return $this->buildDoNo(lock: false);
    }

    /**
     * "Recall SO" payload: the undelivered remainder of a confirmed sales order.
     *
     * @return array<string, mixed>
     */
    public function fromSalesOrder(int $soId): array
    {
        $so = SalesOrder::with([
            'items.product.baseUnit', 'items.unit', 'items.attribute',
            'customer', 'salesPerson', 'orderTakenBy',
        ])->findOrFail($soId);

        if ($so->status !== SalesOrderStatus::Confirmed) {
            abort(422, 'Delivery orders can only be created from confirmed sales orders.');
        }

        // Rolls already sitting on any active (draft or confirmed) DO are not available
        $takenPieceIds = DeliveryOrderPiece::pluck('piece_id')->all();

        $items = $so->items->map(function (SalesOrderItem $item) use ($so, $takenPieceIds): array {
            $remaining = max(0.0, (float) $item->quantity - (float) $item->quantity_delivered);

            // The line is sold in one UOM (Yard) but the rolls are weighed in the product's
            // stocking UOM (Kg). The picker sees both, so both symbols travel with the line.
            $baseUnit   = $item->product?->baseUnit;
            $lineUnit   = $item->unit ?? $baseUnit;
            $unitSymbol = fn (?UnitType $u) => $u?->symbol ?? $u?->name;

            $availablePieces = [];
            if ($item->is_scanned) {
                $availablePieces = SalesOrderPiece::with(['piece.store', 'piece.location', 'piece.batch', 'piece.grn'])
                    ->where('so_id', $so->id)
                    ->where('so_item_id', $item->id)
                    ->get()
                    ->filter(fn (SalesOrderPiece $sp) =>
                        $sp->piece?->status === GrnItemPiece::STATUS_ALLOCATED
                        && !in_array($sp->piece_id, $takenPieceIds, true))
                    ->map(fn (SalesOrderPiece $sp) => [
                        'so_piece_id' => $sp->id,
                        'piece_id'    => $sp->piece_id,
                        'piece_code'  => $sp->piece_code,
                        'roll_no'     => $sp->piece?->roll_no,
                        // What the roll holds vs. what this sale takes off it — a cut roll
                        // ships less than its weight and leaves a remnant behind.
                        'weight'         => (float) $sp->weight,
                        'taken_quantity' => (float) $sp->taken_quantity,
                        'is_cut'         => (float) $sp->taken_quantity < (float) $sp->weight - self::EPSILON,
                        'store'       => $sp->piece?->store?->store_name,
                        'location'    => $sp->piece?->location?->location_name ?? $sp->piece?->location?->name,
                        'batch_no'    => $sp->piece?->batch?->batch_no,
                        'grn_no'      => $sp->piece?->grn?->grn_no,
                    ])
                    ->values()
                    ->all();
            }

            return [
                'so_item_id'         => $item->id,
                'product'            => [
                    'id'           => $item->product?->id,
                    'name'         => $item->product?->name,
                    'product_code' => $item->product?->product_code,
                ],
                'unit'               => $lineUnit ? ['id' => $lineUnit->id, 'name' => $lineUnit->name, 'symbol' => $unitSymbol($lineUnit)] : null,
                'base_unit'          => $baseUnit ? ['id' => $baseUnit->id, 'name' => $baseUnit->name, 'symbol' => $unitSymbol($baseUnit)] : null,
                // Roll weights are in base UOM; dividing by this yields the line UOM the
                // customer ordered in — the same arithmetic syncItems() applies on save.
                'conversion_factor'  => (float) $item->conversion_factor ?: 1.0,
                'attribute'          => $item->attribute ? ['id' => $item->attribute->id, 'name' => $item->attribute->attribute_name] : null,
                'is_scanned'         => $item->is_scanned,
                'quantity'           => (float) $item->quantity,
                'quantity_delivered' => (float) $item->quantity_delivered,
                'remaining'          => $remaining,
                'unit_price'         => (float) $item->unit_price,
                'available_pieces'   => $availablePieces,
                'fully_delivered'    => $item->is_scanned
                    ? $availablePieces === [] && $remaining <= self::EPSILON
                    : $remaining <= self::EPSILON,
            ];
        })->values()->all();

        return [
            'sales_order' => [
                'id'               => $so->id,
                'so_no'            => $so->so_no,
                'status'           => $so->status->value,
                'customer_type'    => $so->customer_type,
                'order_source'     => $so->order_source,
                'order_date'       => $so->order_date?->toDateString(),
                'transaction_date' => $so->transaction_date?->toDateString(),
                'sales_person'     => $so->salesPerson?->name,
                'order_taken_by'   => $so->orderTakenBy?->name,
                'delivery_address' => $so->delivery_address,
                'customer'         => $so->customer ? [
                    'id'            => $so->customer->id,
                    'name'          => $so->customer->customer_name,
                    'customer_type' => $so->customer->customer_type,
                ] : null,
            ],
            'items' => $items,
        ];
    }

    public function create(DeliveryOrderData $data): DeliveryOrder
    {
        return DB::transaction(function () use ($data): DeliveryOrder {
            $so = SalesOrder::whereKey($data->soId)->lockForUpdate()->firstOrFail();

            if ($so->status !== SalesOrderStatus::Confirmed) {
                abort(422, 'Delivery orders can only be created from confirmed sales orders.');
            }

            $do = DeliveryOrder::create([
                'do_no'              => $this->buildDoNo(lock: true),
                'document_date'      => $data->documentDate ?? now()->toDateString(),
                'so_id'              => $so->id,
                'customer_id'        => $so->customer_id,
                'driver_id'          => $data->driverId,
                'vehicle_id'         => $data->vehicleId,
                'store_id'           => $data->storeId,
                'location_id'        => $data->locationId,
                'delivery_date'      => $data->deliveryDate,
                'delivery_mode'      => $data->deliveryMode,
                'delivery_vehicle'   => $data->deliveryVehicle,
                'responsible_person' => $data->responsiblePerson,
                'delivery_address'   => $data->deliveryAddress ?? $so->delivery_address,
                'status'             => DeliveryOrderStatus::Draft,
                'remarks'            => $data->remarks,
                'created_by'         => Auth::id(),
            ]);

            $this->syncItems($do, $data->items);

            return $this->find($do->id);
        });
    }

    public function update(DeliveryOrder $do, DeliveryOrderData $data): DeliveryOrder
    {
        return DB::transaction(function () use ($do, $data): DeliveryOrder {
            $do = DeliveryOrder::whereKey($do->id)->lockForUpdate()->firstOrFail();

            if (!$do->status->isEditable()) {
                abort(422, 'Only draft delivery orders can be edited.');
            }
            if ((int) $do->so_id !== $data->soId) {
                abort(422, 'The sales order of a delivery order cannot be changed.');
            }

            $do->update([
                'document_date'      => $data->documentDate ?? $do->document_date,
                'driver_id'          => $data->driverId,
                'vehicle_id'         => $data->vehicleId,
                'store_id'           => $data->storeId,
                'location_id'        => $data->locationId,
                'delivery_date'      => $data->deliveryDate,
                'delivery_mode'      => $data->deliveryMode,
                'delivery_vehicle'   => $data->deliveryVehicle,
                'responsible_person' => $data->responsiblePerson,
                'delivery_address'   => $data->deliveryAddress,
                'remarks'            => $data->remarks,
            ]);

            $this->syncItems($do, $data->items);

            return $this->find($do->id);
        });
    }

    public function updateStatus(DeliveryOrder $do, string $status): DeliveryOrder
    {
        $newStatus = DeliveryOrderStatus::from($status);

        if ($do->status !== DeliveryOrderStatus::Draft) {
            abort(422, "Cannot transition delivery order from {$do->status->label()} to {$newStatus->label()}.");
        }

        return match ($newStatus) {
            DeliveryOrderStatus::Confirmed => $this->confirm($do),
            DeliveryOrderStatus::Cancelled => DB::transaction(function () use ($do): DeliveryOrder {
                // Release the piece rows — the rolls stay allocated to the SO
                $do->pieces()->delete();
                $do->update(['status' => DeliveryOrderStatus::Cancelled]);

                return $do;
            }),
            default => abort(422, 'Invalid status transition.'),
        };
    }

    public function delete(DeliveryOrder $do): void
    {
        if ($do->status !== DeliveryOrderStatus::Draft) {
            abort(422, 'Only draft delivery orders can be deleted.');
        }

        DB::transaction(function () use ($do): void {
            $do->pieces()->delete();
            $do->items()->delete();
            $do->delete();
        });
    }

    private function buildDoNo(bool $lock): string
    {
        $year   = now()->year;
        $prefix = "DO-{$year}-";

        $query = DeliveryOrder::withTrashed()
            ->where('do_no', 'like', $prefix . '%')
            ->orderByDesc('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $last = $query->value('do_no');
        $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Draft-stage item sync — no stock effects. Release-then-rebuild like the SO.
     *
     * @param array<array{so_item_id:int, quantity:?float, piece_ids:?array<int>, remarks:?string}> $items
     */
    private function syncItems(DeliveryOrder $do, array $items): void
    {
        $do->pieces()->delete();
        $do->items()->delete();

        $soItems = SalesOrderItem::where('so_id', $do->so_id)
            ->whereIn('id', array_column($items, 'so_item_id'))
            ->get()
            ->keyBy('id');

        $hasManualLine = false;

        foreach ($items as $row) {
            $soItem = $soItems->get((int) $row['so_item_id']);
            if (!$soItem) {
                abort(422, 'One of the lines does not belong to this sales order.');
            }

            $pieceIds = array_values(array_unique(array_map('intval', (array) ($row['piece_ids'] ?? []))));

            if ($soItem->is_scanned) {
                if ($pieceIds === []) {
                    continue; // roll line with nothing selected — skip
                }

                $doItem = DeliveryOrderItem::create([
                    'do_id'        => $do->id,
                    'so_item_id'   => $soItem->id,
                    'product_id'   => $soItem->product_id,
                    'unit_id'      => $soItem->unit_id,
                    'attribute_id' => $soItem->attribute_id,
                    'is_scanned'   => true,
                    'quantity'     => 0,
                    'remarks'      => $row['remarks'] ?? null,
                ]);

                // attachPieces sums what the sale takes off each roll (stocking UOM); the
                // line itself is shown in the UOM the customer bought in (Yard).
                $factor  = max((float) $soItem->conversion_factor, self::EPSILON);
                $baseQty = $this->attachPieces($do, $doItem, $soItem, $pieceIds);

                $doItem->base_quantity     = $baseQty;
                $doItem->conversion_factor = $factor;
                $doItem->quantity          = round($baseQty / $factor, 4);
                $doItem->save();
            } else {
                $qty = (float) ($row['quantity'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                // Remaining minus what other draft DOs already claim for this line
                $claimedElsewhere = (float) DeliveryOrderItem::where('so_item_id', $soItem->id)
                    ->where('do_id', '!=', $do->id)
                    ->whereHas('deliveryOrder', fn ($q) => $q->where('status', DeliveryOrderStatus::Draft->value))
                    ->sum('quantity');

                $available = (float) $soItem->quantity - (float) $soItem->quantity_delivered - $claimedElsewhere;

                if ($qty > $available + self::EPSILON) {
                    abort(422, "Quantity for {$soItem->product?->name} exceeds the undelivered remainder ({$available}).");
                }

                $hasManualLine = true;

                DeliveryOrderItem::create([
                    'do_id'        => $do->id,
                    'so_item_id'   => $soItem->id,
                    'product_id'   => $soItem->product_id,
                    'unit_id'      => $soItem->unit_id,
                    'attribute_id' => $soItem->attribute_id,
                    'is_scanned'   => false,
                    'quantity'     => $qty,
                    'remarks'      => $row['remarks'] ?? null,
                ]);
            }
        }

        if ($do->items()->count() === 0) {
            abort(422, 'Select at least one roll or quantity to deliver.');
        }

        if ($hasManualLine && (!$do->store_id || !$do->location_id)) {
            abort(422, 'Select the store and location that manual (non-roll) lines ship from.');
        }
    }

    /**
     * Attach selected rolls to a draft DO line. Returns the summed weight.
     *
     * @param array<int> $pieceIds
     */
    private function attachPieces(DeliveryOrder $do, DeliveryOrderItem $doItem, SalesOrderItem $soItem, array $pieceIds): float
    {
        $soPieces = SalesOrderPiece::with('piece')
            ->where('so_id', $do->so_id)
            ->where('so_item_id', $soItem->id)
            ->whereIn('piece_id', $pieceIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('piece_id');

        $rows = [];
        foreach ($pieceIds as $pieceId) {
            $soPiece = $soPieces->get($pieceId);
            if (!$soPiece) {
                abort(422, 'One of the selected rolls is not allocated to this sales order line.');
            }
            if ($soPiece->piece?->status !== GrnItemPiece::STATUS_ALLOCATED) {
                abort(422, "Roll {$soPiece->piece_code} is no longer allocated (status: {$soPiece->piece?->status}).");
            }

            $rows[] = [
                'do_id'       => $do->id,
                'do_item_id'  => $doItem->id,
                'so_piece_id' => $soPiece->id,
                'piece_id'    => $pieceId,
                'piece_code'  => $soPiece->piece_code,
                // The roll's own weight, and the slice of it this sale takes — they
                // differ whenever the customer buys less than a full roll.
                'weight'         => (float) $soPiece->weight,
                'taken_quantity' => (float) $soPiece->taken_quantity,
                'created_by'  => Auth::id(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ];
        }

        try {
            DeliveryOrderPiece::insert($rows);
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                abort(422, 'One of the selected rolls is already on another delivery order.');
            }
            throw $e;
        }

        // Base UOM — what leaves stock, not what the rolls weigh.
        return array_sum(array_column($rows, 'taken_quantity'));
    }

    /** The outbound stock posting — everything in one transaction. */
    private function confirm(DeliveryOrder $do): DeliveryOrder
    {
        return DB::transaction(function () use ($do): DeliveryOrder {
            $do = DeliveryOrder::whereKey($do->id)->lockForUpdate()->firstOrFail();

            if ($do->status !== DeliveryOrderStatus::Draft) {
                abort(422, 'Only draft delivery orders can be confirmed.');
            }

            $so = SalesOrder::whereKey($do->so_id)->lockForUpdate()->firstOrFail();
            if ($so->status !== SalesOrderStatus::Confirmed) {
                abort(422, "Sales order {$so->so_no} is no longer confirmed.");
            }

            $do->load(['items', 'pieces']);
            if ($do->items->isEmpty()) {
                abort(422, 'The delivery order has no lines.');
            }

            $soItems = SalesOrderItem::whereIn('id', $do->items->pluck('so_item_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // ── Validate rolls under lock ────────────────────────────────
            $livePieces = GrnItemPiece::whereIn('id', $do->pieces->pluck('piece_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($do->pieces as $doPiece) {
                $live = $livePieces->get($doPiece->piece_id);
                if (!$live || $live->status !== GrnItemPiece::STATUS_ALLOCATED) {
                    abort(422, "Roll {$doPiece->piece_code} is no longer allocated (status: " . ($live?->status ?? 'missing') . ').');
                }
                $stillOnSo = SalesOrderPiece::where('so_id', $do->so_id)
                    ->where('piece_id', $doPiece->piece_id)
                    ->exists();
                if (!$stillOnSo) {
                    abort(422, "Roll {$doPiece->piece_code} was released from the sales order.");
                }
            }

            // ── Validate manual remainders ───────────────────────────────
            foreach ($do->items->where('is_scanned', false) as $item) {
                $soItem    = $soItems->get($item->so_item_id);
                $remaining = (float) $soItem->quantity - (float) $soItem->quantity_delivered;
                if ((float) $item->quantity > $remaining + self::EPSILON) {
                    abort(422, "Quantity for line {$item->id} exceeds the undelivered remainder ({$remaining}).");
                }
                if (!$do->store_id || !$do->location_id) {
                    abort(422, 'Select the store and location that manual (non-roll) lines ship from.');
                }
            }

            $productsById = Product::with('baseUnit')
                ->whereIn('id', $do->items->pluck('product_id')->unique())
                ->get()
                ->keyBy('id');

            // ── Build outbound groups: do_item × store × location × batch ─
            // Every qty below is in the product's stocking UOM, so it can be compared
            // against and subtracted from current_stock directly.
            $groups = []; // key => [do_item, product_id, store_id, location_id, batch_id, qty, unit_id, unit_price, piece_row_ids[]]
            foreach ($do->items as $item) {
                $soItem     = $soItems->get($item->so_item_id);
                $product    = $productsById->get($item->product_id);
                $baseUnitId = $this->units->baseUnitIdFor($product);

                // The SO quotes the customer a price per the SO line's UOM, but the
                // ledger's qty_out is in base units — so the price must be rebased too,
                // or qty × unit_price stops being the line's value (which is what the
                // valuation reports compute).
                $lineFactor    = $this->units->factor($item->unit_id ?? $baseUnitId, $baseUnitId);
                $basePrice     = $lineFactor > 0 ? (float) $soItem->unit_price / $lineFactor : 0.0;

                if ($item->is_scanned) {
                    // Roll weights are already in the stocking UOM (sealed at GRN confirm),
                    // and taken_quantity is the slice of each roll this sale takes — which
                    // is what leaves stock. The rest of the roll stays, as a remnant.
                    foreach ($do->pieces->where('do_item_id', $item->id) as $doPiece) {
                        $live = $livePieces->get($doPiece->piece_id);
                        // Grouped by DO item, and a DO item is colour-specific — so two
                        // colours of one product never collapse into a single ledger row.
                        $key  = "{$item->id}|{$live->store_id}|{$live->location_id}|{$live->batch_id}";
                        $groups[$key] ??= [
                            'product_id' => $item->product_id,
                            'attribute_id' => $item->attribute_id,
                            'store_id'   => $live->store_id,
                            'location_id' => $live->location_id,
                            'batch_id'   => $live->batch_id,
                            'qty'        => 0.0,
                            'unit_id'    => $baseUnitId,
                            'entered_unit_id' => $item->unit_id ?? $baseUnitId,
                            'line_factor'     => $lineFactor,
                            'unit_price' => $basePrice,
                            'piece_rows' => [],
                            'piece_meta' => [],
                        ];
                        $groups[$key]['qty']         += (float) $doPiece->taken_quantity;
                        $groups[$key]['piece_rows'][] = $doPiece->id;
                        $groups[$key]['piece_meta'][$doPiece->id] = [
                            'store_id'    => $live->store_id,
                            'location_id' => $live->location_id,
                            'batch_id'    => $live->batch_id,
                        ];
                    }
                } else {
                    // Manual line: the user typed a quantity in the line's UOM (say g)
                    // which may differ from the stocking UOM (say Kg).
                    $line = $this->units->toBase($product, $item->unit_id, (float) $item->quantity);

                    $item->update([
                        'conversion_factor' => $line['factor'],
                        'base_quantity'     => $line['qty'],
                    ]);

                    $groups["{$item->id}|manual"] = [
                        'product_id' => $item->product_id,
                        'attribute_id' => $item->attribute_id,
                        'store_id'   => $do->store_id,
                        'location_id' => $do->location_id,
                        'batch_id'   => null,
                        'qty'        => $line['qty'],
                        'unit_id'    => $baseUnitId,
                        'entered_unit_id' => $item->unit_id ?? $baseUnitId,
                        'line_factor'     => $lineFactor,
                        'unit_price' => $basePrice,
                        'piece_rows' => [],
                        'piece_meta' => [],
                    ];
                }
            }

            // ── Availability check per product × store × location ────────
            $needs = [];
            foreach ($groups as $group) {
                $key = "{$group['product_id']}|{$group['store_id']}|{$group['location_id']}";
                $needs[$key] = ($needs[$key] ?? 0.0) + $group['qty'];
            }

            foreach ($needs as $key => $needed) {
                [$productId, $storeId, $locationId] = array_map(
                    fn ($v) => $v === '' ? null : (int) $v,
                    explode('|', $key),
                );

                $pivot = ProductLocationStore::where('product_id', $productId)
                    ->where('store_id', $storeId)
                    ->where('location_id', $locationId)
                    ->lockForUpdate()
                    ->first();

                $balance = (float) ($pivot?->current_stock ?? 0);
                $product = $productsById->get($productId);

                if ($balance - $needed < -self::EPSILON && !$product?->allow_minus) {
                    // Both figures are in the stocking UOM — naming it avoids the
                    // "but I have 100 Kg!" confusion when the line was priced in g.
                    $symbol = $product?->baseUnit?->symbol ?? $product?->baseUnit?->name ?? '';

                    abort(422, sprintf(
                        'Insufficient stock for %s: available %s %s, required %s %s.',
                        $product?->name ?? "product #{$productId}",
                        number_format($balance, 4),
                        $symbol,
                        number_format($needed, 4),
                        $symbol,
                    ));
                }
            }

            // ── Post ledger rows + pivot/batch decrements + piece stamping ─
            foreach ($groups as $group) {
                $batch = $group['batch_id'] ? Batch::find($group['batch_id']) : null;

                // qty_out is in the stocking UOM; entered_qty restates it in the UOM the
                // customer actually bought in, so a sale of 300 yd does not surface in the
                // ledger only as 274.32 m.
                $lineFactor = (float) ($group['line_factor'] ?? 1.0) ?: 1.0;

                $txn = StockTransaction::create([
                    'transaction_date' => now(),
                    'reference_type'   => StockReferenceType::CODE_SALES_DELIVERY,
                    'reference_id'     => $do->id,
                    'product_id'       => $group['product_id'],
                    'attribute_id'     => $group['attribute_id'],
                    'store_id'         => $group['store_id'],
                    'location_id'      => $group['location_id'],
                    'batch_no'         => $batch?->batch_no,
                    'batch_id'         => $group['batch_id'],
                    'expiry_date'      => $batch?->expiry_date,
                    'qty_in'           => 0,
                    'qty_out'          => $group['qty'],
                    'unit_id'          => $group['unit_id'],
                    'entered_unit_id'  => $group['entered_unit_id'],
                    'entered_qty'      => $group['qty'] / $lineFactor,
                    'unit_price'       => $group['unit_price'], // SO selling price
                    'created_by'       => Auth::id(),
                ]);

                $pivot = ProductLocationStore::where('product_id', $group['product_id'])
                    ->where('store_id', $group['store_id'])
                    ->where('location_id', $group['location_id'])
                    ->first();

                if ($pivot) {
                    $pivot->decrement('current_stock', $group['qty']);
                } else {
                    // Only reachable when allow_minus permitted the shortfall
                    ProductLocationStore::create([
                        'product_id'    => $group['product_id'],
                        'store_id'      => $group['store_id'],
                        'location_id'   => $group['location_id'],
                        'current_stock' => -$group['qty'],
                    ]);
                }

                if ($batch) {
                    $batch->decrement('current_qty', $group['qty']);
                }

                foreach ($group['piece_rows'] as $doPieceId) {
                    DeliveryOrderPiece::whereKey($doPieceId)->update([
                        'stock_transaction_id' => $txn->id,
                        ...$group['piece_meta'][$doPieceId],
                    ]);
                }
            }

            // ── Rolls leave stock; partly-sold ones are cut ───────────────
            foreach ($do->pieces as $doPiece) {
                $this->rolls->shipOrCut(
                    $livePieces->get($doPiece->piece_id),
                    (float) $doPiece->taken_quantity,
                );
            }

            // ── Fulfillment + SO auto-complete ───────────────────────────
            foreach ($do->items as $item) {
                $soItems->get($item->so_item_id)?->increment('quantity_delivered', (float) $item->quantity);
            }

            $fullyDelivered = SalesOrderItem::where('so_id', $so->id)
                ->get()
                ->every(fn (SalesOrderItem $line) =>
                    (float) $line->quantity_delivered >= (float) $line->quantity - self::EPSILON);

            if ($fullyDelivered) {
                $so->update(['status' => SalesOrderStatus::Completed]);
            }

            $do->update([
                'status'       => DeliveryOrderStatus::Confirmed,
                'confirmed_at' => now(),
                'confirmed_by' => Auth::id(),
            ]);

            return $this->find($do->id);
        });
    }

}
