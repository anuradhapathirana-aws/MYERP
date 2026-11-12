<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Contracts\JournalPoster;
use App\Support\Journal\PostingRole;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\SupplierReturnData;
use Modules\Inventory\Enums\CreditNoteStatus;
use Modules\Inventory\Enums\CreditNoteType;
use Modules\Inventory\Enums\GrnStatus;
use Modules\Inventory\Enums\SupplierReturnStatus;
use Modules\Inventory\Models\Attribute;
use Modules\Inventory\Models\Batch;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GoodsReceivedNoteItem;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\ProductLocationStore;
use Modules\Inventory\Models\ProductVariant;
use Modules\Inventory\Models\StockReferenceType;
use Modules\Inventory\Models\StockTransaction;
use Modules\Inventory\Models\Store;
use Modules\Inventory\Models\SupplierCreditNote;
use Modules\Inventory\Models\SupplierReturn;
use Modules\Inventory\Models\SupplierReturnItem;
use Modules\Inventory\Models\SupplierReturnPiece;
use Modules\Inventory\Posting\SupplierReturnJournal;
use Modules\Inventory\Support\Quantity;

/**
 * Supplier (purchase) returns, raised against one confirmed GRN: Draft → Confirm.
 *
 * Confirming a return reverses part of the GRN: an outbound supplier_return ledger row
 * per line (per roll for roll lines), priced at the same base cost the GRN received it
 * at. A roll goes back whole, or is cut — the part going back is retired as Returned and
 * the offcut stays in stock under a new "-C" code (RollService::returnToSupplier()).
 *
 * The money side debits the supplier exactly what the GRN charged for the returned
 * quantity: first against what the GRN still owes (applied_to_grn — read by
 * GrnBalanceService), any excess (the GRN was already paid) as an open supplier_return
 * credit note to set off on a future supplier payment.
 *
 * Returnable quantity = received − already returned on CONFIRMED returns. Drafts reserve
 * nothing; confirm() re-checks under the GRN row lock, so two drafts can never both
 * return the same goods.
 */
class SupplierReturnService
{
    public function __construct(
        private readonly UnitConversionService $units,
        private readonly RollService $rolls,
        private readonly GrnBalanceService $balances,
        private readonly ColourStockService $colourStock,
        private readonly VariantStockService $variantStock,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = SupplierReturn::with([
            'supplier:id,supplier_code,supplier_name',
            'grn:id,grn_no',
            'store:id,store_code,store_name',
        ])->orderByDesc('return_date')->orderByDesc('id');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term): void {
                $q->where('return_no', 'like', $term)
                  ->orWhereHas('grn', fn ($g) => $g->where('grn_no', 'like', $term));
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }

        if (!empty($filters['grn_id'])) {
            $query->where('grn_id', (int) $filters['grn_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('return_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('return_date', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    public function find(int $id): SupplierReturn
    {
        return SupplierReturn::with([
            'supplier:id,supplier_code,supplier_name',
            'grn:id,grn_no,grn_date,reference_no,total_amount,status',
            'store:id,store_code,store_name',
            'creditNote:id,credit_note_no,amount,remaining_balance,status',
            'createdBy:id,name',
            'confirmedBy:id,name',
            'items.product:id,product_code,name',
            'items.attribute:id,attribute_name',
            'items.variant:id,sku,variant_label',
            'items.unit:id,name,symbol',
            'items.store:id,store_code,store_name',
            'items.pieces.remnantPiece:id,piece_code',
        ])->findOrFail($id);
    }

    /**
     * Confirmed GRNs of a supplier that still have something left to return — the GRN
     * picker on the return form.
     *
     * @return array<int, array<string, mixed>>
     */
    public function returnableGrns(int $supplierId): array
    {
        $grns = GoodsReceivedNote::where('supplier_id', $supplierId)
            ->where('status', GrnStatus::Confirmed->value)
            ->orderByDesc('grn_date')
            ->orderByDesc('id')
            ->get(['id', 'grn_no', 'grn_date', 'reference_no', 'total_amount']);

        if ($grns->isEmpty()) {
            return [];
        }

        $grnIds = $grns->pluck('id')->all();

        $receivedQty = GoodsReceivedNoteItem::whereIn('grn_id', $grnIds)
            ->groupBy('grn_id')
            ->selectRaw('grn_id, SUM(quantity_received) as qty')
            ->pluck('qty', 'grn_id');

        $returnedQty = DB::table('inv_supplier_return_items as ri')
            ->join('inv_supplier_returns as r', 'r.id', '=', 'ri.return_id')
            ->whereIn('r.grn_id', $grnIds)
            ->where('r.status', SupplierReturnStatus::Confirmed->value)
            ->whereNull('r.deleted_at')
            ->groupBy('r.grn_id')
            ->selectRaw('r.grn_id, SUM(ri.quantity) as qty')
            ->pluck('qty', 'grn_id');

        $outstanding = $this->balances->outstandingFor($grns->pluck('total_amount', 'id')->all());

        return $grns
            ->filter(fn (GoodsReceivedNote $g) => (float) ($receivedQty[$g->id] ?? 0) - (float) ($returnedQty[$g->id] ?? 0) > Quantity::EPSILON)
            ->map(fn (GoodsReceivedNote $g) => [
                'grn_id'       => $g->id,
                'grn_no'       => $g->grn_no,
                'grn_date'     => $g->grn_date?->toDateString(),
                'reference_no' => $g->reference_no,
                'total_amount' => (float) $g->total_amount,
                'outstanding'  => max(0.0, $outstanding[$g->id] ?? 0.0),
            ])
            ->values()
            ->all();
    }

    /**
     * Everything the return form needs to show one GRN's lines: what was received, what
     * has already gone back, what can still go back — and for roll lines, each roll of
     * the line still in stock. Quantities are in the GRN line's UOM.
     *
     * @return array<string, mixed>
     */
    public function returnableItems(int $grnId): array
    {
        $grn = GoodsReceivedNote::with([
            'supplier:id,supplier_code,supplier_name',
            'items.product:id,product_code,name,base_unit_type_id,stock_tracking',
            'items.attribute:id,attribute_name',
            'items.variant:id,sku,variant_label',
            'items.unit:id,name,symbol',
        ])->findOrFail($grnId);

        $this->assertReturnable($grn);

        $lines = $this->returnableLines($grn);

        return [
            'grn' => [
                'id'               => $grn->id,
                'grn_no'           => $grn->grn_no,
                'grn_date'         => $grn->grn_date?->toDateString(),
                'reference_no'     => $grn->reference_no,
                'total_amount'     => (float) $grn->total_amount,
                'outstanding'      => max(0.0, $this->balances->outstanding($grn->id)),
                'supplier_id'      => $grn->supplier_id,
                'supplier'         => $grn->supplier ? [
                    'id'            => $grn->supplier->id,
                    'supplier_code' => $grn->supplier->supplier_code,
                    'name'          => $grn->supplier->supplier_name,
                ] : null,
                // Where the goods were received — the natural place for them to leave from.
                'default_store_id' => $grn->store_id,
            ],
            'items' => array_values(array_map(fn (array $line) => [
                'grn_item_id'    => $line['item']->id,
                'product_id'     => $line['item']->product_id,
                'product_code'   => $line['item']->product?->product_code,
                'product_name'   => $line['item']->product?->name,
                'attribute_name' => $line['item']->attribute?->attribute_name,
                'variant_label'  => $line['item']->variant?->variant_label,
                'unit_name'      => $line['item']->unit?->symbol ?: $line['item']->unit?->name,
                'is_scanned'     => $line['is_scanned'],
                'received_qty'   => (float) $line['item']->quantity_received,
                'returned_qty'   => $line['returned'],
                'returnable_qty' => $line['returnable'],
                'unit_price'     => (float) $line['item']->unit_price,
                'discount'       => (float) $line['item']->discount,
                'tax'            => (float) $line['item']->tax,
                'line_total'     => (float) $line['item']->line_total,
                'pieces'         => array_values(array_map(fn (array $piece) => [
                    'piece_id'      => $piece['roll']->id,
                    'piece_code'    => $piece['roll']->piece_code,
                    'store_name'    => $piece['roll']->store?->store_name,
                    'available_qty' => $piece['available'],
                ], $line['pieces'])),
            ], $lines)),
        ];
    }

    public function create(SupplierReturnData $data): SupplierReturn
    {
        return DB::transaction(function () use ($data): SupplierReturn {
            $grn = $this->grnFor($data);

            $return = SupplierReturn::create([
                'return_no'  => $this->generateReturnNo(),
                ...$this->headerAttributes($data, $grn),
                'status'     => SupplierReturnStatus::Draft,
                'created_by' => Auth::id(),
            ]);

            $this->syncItems($return, $grn, $data);

            return $this->find($return->id);
        });
    }

    public function update(SupplierReturn $return, SupplierReturnData $data): SupplierReturn
    {
        if ($return->status !== SupplierReturnStatus::Draft) {
            abort(422, 'Only draft returns can be edited.');
        }

        return DB::transaction(function () use ($return, $data): SupplierReturn {
            $grn = $this->grnFor($data);

            $return->update($this->headerAttributes($data, $grn));
            $this->syncItems($return, $grn, $data);

            return $this->find($return->id);
        });
    }

    public function delete(SupplierReturn $return): void
    {
        if ($return->status !== SupplierReturnStatus::Draft) {
            abort(422, 'Only draft returns can be deleted.');
        }

        DB::transaction(function () use ($return): void {
            $return->pieces()->delete();
            $return->items()->delete();
            $return->delete();
        });
    }

    /** Post the return: stock out, rolls retired or cut, supplier debited — one transaction. */
    public function confirm(SupplierReturn $return): SupplierReturn
    {
        return DB::transaction(function () use ($return): SupplierReturn {
            $return = SupplierReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();

            if ($return->status !== SupplierReturnStatus::Draft) {
                abort(422, 'Only draft returns can be confirmed.');
            }

            // The GRN row lock serialises every confirm against this GRN, so the
            // returnable figures below cannot change underneath us.
            $grn = GoodsReceivedNote::whereKey($return->grn_id)->lockForUpdate()->firstOrFail();
            $grn->load(['items.product.baseUnit', 'supplier']);
            $this->assertReturnable($grn);

            $return->load(['items.pieces']);
            if ($return->items->isEmpty()) {
                abort(422, 'The return has no lines.');
            }

            $lines = collect($this->returnableLines($grn))->keyBy(fn (array $line) => $line['item']->id);

            foreach ($return->items as $item) {
                $this->assertWithinReturnable($item, $lines->get($item->grn_item_id));
            }

            $this->assertStockCovers($return, $lines);

            foreach ($return->items as $item) {
                $line = $lines->get($item->grn_item_id);

                $line['is_scanned']
                    ? $this->sendRolls($return, $item, $line)
                    : $this->sendManual($return, $item, $line);
            }

            $this->debitSupplier($return, $grn);

            $return->update([
                'status'       => SupplierReturnStatus::Confirmed,
                'confirmed_at' => now(),
                'confirmed_by' => Auth::id(),
            ]);

            // Only a GRN that reached the ledger can be reversed in it. One confirmed
            // before Finance was switched on never credited the supplier, so debiting
            // them for its return would leave the books short.
            $poster = app(JournalPoster::class);

            if ($poster->acceptsPostings() && $poster->hasPostingFor(PostingRole::SOURCE_GRN, (int) $grn->id)) {
                $poster->post(SupplierReturnJournal::build($return->fresh(['items', 'supplier', 'grn'])));
            }

            return $this->find($return->id);
        });
    }

    /** Preview the next return number (non-locking, for display only) */
    public function nextReturnNo(): string
    {
        return $this->buildReturnNo(lock: false);
    }

    // ── Lines ────────────────────────────────────────────────────────────────

    /**
     * Rebuild a draft's lines from the payload, pricing each one off the GRN line it
     * returns and checking it against what is still returnable.
     */
    private function syncItems(SupplierReturn $return, GoodsReceivedNote $grn, SupplierReturnData $data): void
    {
        $return->pieces()->delete();
        $return->items()->delete();

        $lines = collect($this->returnableLines($grn))->keyBy(fn (array $line) => $line['item']->id);
        $total = 0.0;

        foreach ($data->items as $row) {
            $line = $lines->get((int) $row['grn_item_id']);
            abort_if($line === null, 422, 'One of the lines does not belong to the selected GRN.');

            /** @var GoodsReceivedNoteItem $grnItem */
            $grnItem = $line['item'];
            $factor  = $line['factor'];

            [$quantity, $pieceRows] = $line['is_scanned']
                ? $this->pieceRowsFor($line, (array) ($row['pieces'] ?? []), $factor)
                : [Quantity::round((float) ($row['quantity'] ?? 0)), []];

            abort_if(
                ! Quantity::isPositive($quantity),
                422,
                "Enter the quantity being returned for {$grnItem->product?->name}.",
            );

            $lineTotal = $this->lineValue($grnItem, $quantity);
            $total    += $lineTotal;

            $item = SupplierReturnItem::create([
                'return_id'     => $return->id,
                'grn_item_id'   => $grnItem->id,
                'product_id'    => $grnItem->product_id,
                'attribute_id'  => $grnItem->attribute_id,
                'variant_id'    => $grnItem->variant_id,
                'unit_id'       => $grnItem->unit_id,
                'quantity'      => $quantity,
                'base_quantity' => $line['is_scanned']
                    ? Quantity::round((float) collect($pieceRows)->sum('returned_quantity'))
                    : Quantity::round($quantity * $factor),
                'unit_price'    => $grnItem->unit_price,
                'discount'      => $grnItem->discount,
                'tax'           => $grnItem->tax,
                'line_total'    => $lineTotal,
                'store_id'      => $return->store_id,
                'location_id'   => $return->location_id,
                'reason'        => $row['reason'],
                'remarks'       => $row['remarks'] ?? null,
            ]);

            $this->assertWithinReturnable($item, $line, $pieceRows);

            foreach ($pieceRows as $pieceRow) {
                SupplierReturnPiece::create([
                    'return_id'      => $return->id,
                    'return_item_id' => $item->id,
                    ...$pieceRow,
                ]);
            }
        }

        $return->update(['total_amount' => round($total, 2)]);
    }

    /**
     * Per-roll quantities for a roll line, keyed to the line's rolls still in stock.
     *
     * @param  array<string, mixed>                                  $line
     * @param  array<array{piece_id:int|string, quantity:float|string}> $pieces
     * @return array{0: float, 1: array<int, array<string, mixed>>} [line quantity, piece rows]
     */
    private function pieceRowsFor(array $line, array $pieces, float $factor): array
    {
        abort_if(empty($pieces), 422, 'Select the rolls going back for ' . ($line['item']->product?->name ?? 'this line') . '.');

        $inStock  = collect($line['pieces'])->keyBy(fn (array $p) => $p['roll']->id);
        $rows     = [];
        $quantity = 0.0;

        foreach ($pieces as $piece) {
            $known = $inStock->get((int) $piece['piece_id']);
            abort_if($known === null, 422, 'One of the selected rolls is not in stock on this GRN line.');

            /** @var GrnItemPiece $roll */
            $roll    = $known['roll'];
            $weight  = (float) $roll->weight;
            $qty     = Quantity::round((float) $piece['quantity']);
            $baseQty = Quantity::round($qty * $factor);

            abort_if(
                $baseQty - $weight > Quantity::toleranceFor($factor),
                422,
                sprintf(
                    'Roll %s holds only %s — cannot return %s.',
                    $roll->piece_code,
                    Quantity::format($known['available']),
                    Quantity::format($qty),
                ),
            );

            // Sending (near enough) the whole roll must take exactly what it holds, not a
            // UOM round trip of it — otherwise a whole roll goes back as a cut one and a
            // dust-sized "-C" roll is left behind.
            if (! Quantity::isSellable($weight - $baseQty)) {
                $qty     = $known['available'];
                $baseQty = Quantity::round($weight);
            }

            $quantity += $qty;

            $rows[] = [
                'piece_id'          => $roll->id,
                'piece_code'        => $roll->piece_code,
                'quantity'          => $qty,
                'returned_quantity' => $baseQty,
            ];
        }

        return [Quantity::round($quantity), $rows];
    }

    /**
     * Each GRN line with its returnable figures (line UOM). Roll lines also list the
     * line's rolls still in stock — the originals, and any "-C"/"-R" roll cut or
     * restored from them, all of which keep the GRN line id.
     *
     * @return array<int, array{item: GoodsReceivedNoteItem, is_scanned: bool, factor: float, returned: float, returnable: float, pieces: array<int, array{roll: GrnItemPiece, available: float}>}>
     */
    private function returnableLines(GoodsReceivedNote $grn): array
    {
        $grn->loadMissing('items.product');

        $itemIds = $grn->items->pluck('id');

        $rollLineIds = GrnItemPiece::whereIn('grn_item_id', $itemIds)
            ->where('status', '!=', GrnItemPiece::STATUS_DRAFT)
            ->distinct()
            ->pluck('grn_item_id')
            ->flip();

        $inStock = GrnItemPiece::with('store:id,store_name')
            ->whereIn('grn_item_id', $itemIds)
            ->where('status', GrnItemPiece::STATUS_IN_STOCK)
            ->where('weight', '>', 0)
            ->orderBy('piece_code')
            ->get()
            ->groupBy('grn_item_id');

        $returnedByItem = SupplierReturnItem::whereIn('grn_item_id', $itemIds)
            ->whereHas('supplierReturn', fn ($q) => $q->where('status', SupplierReturnStatus::Confirmed->value))
            ->groupBy('grn_item_id')
            ->selectRaw('grn_item_id, SUM(quantity) as qty')
            ->pluck('qty', 'grn_item_id');

        $lines = [];

        foreach ($grn->items as $item) {
            $isScanned = $rollLineIds->has($item->id);
            $factor    = $this->lineFactor($item);
            $returned  = (float) ($returnedByItem[$item->id] ?? 0);

            $pieces = [];
            if ($isScanned) {
                foreach ($inStock->get($item->id, collect()) as $roll) {
                    // A roll's weight is in the stocking UOM; restate it in the line's UOM.
                    $pieces[] = [
                        'roll'      => $roll,
                        'available' => Quantity::round((float) $roll->weight / $factor),
                    ];
                }
            }

            $lines[] = [
                'item'       => $item,
                'is_scanned' => $isScanned,
                'factor'     => $factor,
                'returned'   => $returned,
                'returnable' => max(0.0, Quantity::round((float) $item->quantity_received - $returned)),
                'pieces'     => $pieces,
            ];
        }

        return $lines;
    }

    /**
     * @param array<string, mixed>|null             $line
     * @param array<int, array<string, mixed>>|null $pieceRows draft rows not yet saved; defaults to the item's saved pieces
     */
    private function assertWithinReturnable(SupplierReturnItem $item, ?array $line, ?array $pieceRows = null): void
    {
        abort_if($line === null, 422, 'One of the lines no longer exists on the GRN.');

        $name      = $line['item']->product?->name ?? "line #{$item->grn_item_id}";
        $tolerance = Quantity::toleranceFor($line['factor']);

        abort_if(
            (float) $item->quantity - $line['returnable'] > $tolerance,
            422,
            sprintf(
                'Cannot return %s of %s — only %s is still returnable on this GRN.',
                Quantity::format((float) $item->quantity),
                $name,
                Quantity::format($line['returnable']),
            ),
        );

        $pieces    = collect($line['pieces'])->keyBy(fn (array $p) => $p['roll']->id);
        $pieceRows ??= $item->pieces->map(fn (SupplierReturnPiece $p) => $p->only(['piece_id', 'piece_code', 'returned_quantity']))->all();

        foreach ($pieceRows as $row) {
            $piece = $pieces->get((int) $row['piece_id']);

            abort_if(
                $piece === null || (float) $row['returned_quantity'] - (float) $piece['roll']->weight > $tolerance,
                422,
                $piece === null
                    ? "Roll {$row['piece_code']} is no longer in stock — it may have been allocated or sold. Remove it from the return."
                    : sprintf('Roll %s: only %s is still in stock.', $row['piece_code'], Quantity::format($piece['available'])),
            );
        }
    }

    /**
     * Quantity lines must leave from stock that is really there: the store balance, and
     * for colour or variant lines that colour's / variant's own balance — the same checks
     * a delivery runs. Roll lines are already proven by their in-stock rolls.
     *
     * @param Collection<int, array<string, mixed>> $lines keyed by GRN item id
     */
    private function assertStockCovers(SupplierReturn $return, Collection $lines): void
    {
        $needs = [];

        foreach ($return->items as $item) {
            $line = $lines->get($item->grn_item_id);
            if ($line['is_scanned'] || $line['item']->product?->allow_minus) {
                continue;
            }

            $key = implode('|', [$item->product_id, $item->variant_id, $item->attribute_id, $item->store_id, $item->location_id]);
            $needs[$key] = [
                'item' => $item,
                'qty'  => ($needs[$key]['qty'] ?? 0.0) + (float) $item->base_quantity,
            ];
        }

        foreach ($needs as ['item' => $item, 'qty' => $needed]) {
            $product = $lines->get($item->grn_item_id)['item']->product;

            $pivot = ProductLocationStore::where('product_id', $item->product_id)
                ->where('store_id', $item->store_id)
                ->where('location_id', $item->location_id)
                ->lockForUpdate()
                ->first();

            [$balance, $label] = match (true) {
                $item->variant_id !== null => [
                    $this->variantStock->available($item->product_id, $item->variant_id, $item->store_id, $item->location_id),
                    ProductVariant::whereKey($item->variant_id)->value('variant_label'),
                ],
                $item->attribute_id !== null => [
                    $this->colourStock->available($item->product_id, $item->attribute_id, $item->store_id, $item->location_id),
                    Attribute::whereKey($item->attribute_id)->value('attribute_name'),
                ],
                default => [(float) ($pivot?->current_stock ?? 0), null],
            };

            if ($balance - $needed < -Quantity::EPSILON) {
                $symbol = $product?->baseUnit?->symbol ?? $product?->baseUnit?->name ?? '';

                abort(422, sprintf(
                    'Insufficient stock for %s%s in the selected store: available %s %s, required %s %s.',
                    $product?->name ?? "product #{$item->product_id}",
                    $label ? " ({$label})" : '',
                    Quantity::format($balance),
                    $symbol,
                    Quantity::format($needed),
                    $symbol,
                ));
            }
        }
    }

    // ── Posting ──────────────────────────────────────────────────────────────

    /**
     * A quantity line leaves from the return's store. A batch-tracked GRN line draws on
     * the batches it received, oldest first, one ledger row per batch.
     *
     * @param array<string, mixed> $line
     */
    private function sendManual(SupplierReturn $return, SupplierReturnItem $item, array $line): void
    {
        $remaining = (float) $item->base_quantity;
        $factor    = $line['factor'];

        $batches = Batch::whereIn('id', $line['item']->batchAssignments()->pluck('batch_id'))
            ->where('current_qty', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if (! Quantity::isPositive($remaining)) {
                break;
            }

            $take      = min($remaining, (float) $batch->current_qty);
            $remaining = Quantity::round($remaining - $take);

            $this->postOutbound($return, $item, $line, $item->store_id, $item->location_id, $take, $take / $factor, $batch);
        }

        if (Quantity::isPositive($remaining)) {
            $this->postOutbound($return, $item, $line, $item->store_id, $item->location_id, $remaining, $remaining / $factor, null);
        }
    }

    /**
     * Each roll leaves on its own ledger row — from the store it actually sits in, with
     * its batch — and is then retired, or cut when only part of it goes back.
     *
     * @param array<string, mixed> $line
     */
    private function sendRolls(SupplierReturn $return, SupplierReturnItem $item, array $line): void
    {
        foreach ($item->pieces as $returnPiece) {
            $roll = GrnItemPiece::whereKey($returnPiece->piece_id)->lockForUpdate()->firstOrFail();

            abort_if(
                $roll->status !== GrnItemPiece::STATUS_IN_STOCK,
                422,
                "Roll {$roll->piece_code} is no longer in stock (status: {$roll->status}) — it cannot be returned.",
            );

            $batch = $roll->batch_id ? Batch::whereKey($roll->batch_id)->lockForUpdate()->first() : null;

            $txn = $this->postOutbound(
                $return,
                $item,
                $line,
                (int) $roll->store_id,
                (int) $roll->location_id,
                (float) $returnPiece->returned_quantity,
                (float) $returnPiece->quantity,
                $batch,
            );

            $remnant = $this->rolls->returnToSupplier($roll, (float) $returnPiece->returned_quantity);

            $returnPiece->update([
                'remnant_piece_id'     => $remnant?->id,
                'store_id'             => $roll->store_id,
                'location_id'          => $roll->location_id,
                'batch_id'             => $roll->batch_id,
                'stock_transaction_id' => $txn->id,
            ]);
        }
    }

    /**
     * One outbound ledger row + the store balance (and batch) it leaves.
     *
     * @param array<string, mixed> $line
     */
    private function postOutbound(
        SupplierReturn $return,
        SupplierReturnItem $item,
        array $line,
        int $storeId,
        int $locationId,
        float $baseQty,
        float $enteredQty,
        ?Batch $batch,
    ): StockTransaction {
        /** @var GoodsReceivedNoteItem $grnItem */
        $grnItem = $line['item'];

        $txn = StockTransaction::create([
            'transaction_date' => now(),
            'reference_type'   => StockReferenceType::CODE_SUPPLIER_RETURN,
            'reference_id'     => $return->id,
            'product_id'       => $item->product_id,
            'attribute_id'     => $item->attribute_id,
            'variant_id'       => $item->variant_id,
            'store_id'         => $storeId,
            'location_id'      => $locationId,
            'batch_no'         => $batch?->batch_no ?? $grnItem->batch_no,
            'batch_id'         => $batch?->id,
            'expiry_date'      => $batch?->expiry_date ?? $grnItem->expiry_date,
            'qty_in'           => 0,
            'qty_out'          => Quantity::round($baseQty),
            'unit_id'          => $this->units->baseUnitIdFor($grnItem->product),
            'entered_unit_id'  => $item->unit_id,
            'entered_qty'      => Quantity::round($enteredQty),
            // The same base cost the GRN received it at, so the return reverses that value.
            'unit_price'       => $this->units->priceToBase((float) $grnItem->unit_price, $line['factor']),
            'created_by'       => Auth::id(),
        ]);

        $pivot = ProductLocationStore::firstOrCreate(
            [
                'product_id'  => $item->product_id,
                'store_id'    => $storeId,
                'location_id' => $locationId,
            ],
            ['current_stock' => 0],
        );
        ProductLocationStore::whereKey($pivot->id)->lockForUpdate()->first()->decrement('current_stock', $baseQty);

        $batch?->decrement('current_qty', $baseQty);

        return $txn;
    }

    /**
     * Apply the return's value to the GRN's outstanding first; whatever the GRN no longer
     * owes (it was already paid) becomes an open supplier_return credit note.
     */
    private function debitSupplier(SupplierReturn $return, GoodsReceivedNote $grn): void
    {
        $total       = round((float) $return->total_amount, 2);
        $outstanding = max(0.0, round($this->balances->outstanding($grn->id), 2));
        $applied     = min($total, $outstanding);
        $excess      = round($total - $applied, 2);

        $creditNoteId = null;

        if ($excess > 0.01) {
            $creditNoteId = SupplierCreditNote::create([
                'credit_note_no'    => $this->balances->generateCreditNoteNo(),
                'supplier_id'       => $return->supplier_id,
                'credit_type'       => CreditNoteType::SupplierReturn,
                'amount'            => $excess,
                'remaining_balance' => $excess,
                'remark'            => "Supplier return {$return->return_no} against GRN {$grn->grn_no}.",
                'status'            => CreditNoteStatus::Open,
                'source_return_id'  => $return->id,
                'created_by'        => Auth::id(),
            ])->id;
        }

        $return->update([
            'applied_to_grn' => $applied,
            'credit_note_id' => $creditNoteId,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** The GRN line's value for $quantity of it — exactly what was charged, pro rata. */
    private function lineValue(GoodsReceivedNoteItem $item, float $quantity): float
    {
        $received = (float) $item->quantity_received;

        return $received > 0 ? round((float) $item->line_total * $quantity / $received, 2) : 0.0;
    }

    /** Line UOM -> stocking UOM, as the GRN posted it (stamped on the line at confirm). */
    private function lineFactor(GoodsReceivedNoteItem $item): float
    {
        $stamped = (float) $item->conversion_factor;

        if ($stamped > 0) {
            return $stamped;
        }

        $baseUnitId = $this->units->baseUnitIdFor($item->product);

        return $this->units->factor($item->unit_id ?? $baseUnitId, $baseUnitId) ?: 1.0;
    }

    private function assertReturnable(GoodsReceivedNote $grn): void
    {
        abort_unless(
            $grn->status === GrnStatus::Confirmed,
            422,
            "GRN {$grn->grn_no} is not confirmed — only confirmed GRNs can be returned against.",
        );
    }

    private function grnFor(SupplierReturnData $data): GoodsReceivedNote
    {
        $grn = GoodsReceivedNote::with('items.product')->findOrFail($data->grnId);

        abort_if($grn->supplier_id !== $data->supplierId, 422, 'The selected GRN does not belong to this supplier.');
        $this->assertReturnable($grn);

        abort_if(
            $grn->grn_date !== null && Carbon::parse($data->returnDate)->startOfDay()->lt($grn->grn_date),
            422,
            "The return date cannot be before the GRN date ({$grn->grn_date->toDateString()}).",
        );

        return $grn;
    }

    /** @return array<string, mixed> */
    private function headerAttributes(SupplierReturnData $data, GoodsReceivedNote $grn): array
    {
        return [
            'return_date' => $data->returnDate,
            'supplier_id' => $data->supplierId,
            'grn_id'      => $grn->id,
            'store_id'    => $data->storeId,
            'location_id' => (int) Store::whereKey($data->storeId)->value('location_id'),
            'remarks'     => $data->remarks,
        ];
    }

    /** Atomically generate the next return number (must be called inside a DB transaction) */
    private function generateReturnNo(): string
    {
        return $this->buildReturnNo(lock: true);
    }

    private function buildReturnNo(bool $lock): string
    {
        $prefix = 'PRN-';

        $last = SupplierReturn::withTrashed()
            ->where('return_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->value('return_no');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
