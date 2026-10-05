<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\CustomerReturnData;
use Modules\Inventory\Enums\CreditNoteStatus;
use Modules\Inventory\Enums\CustomerCreditNoteType;
use Modules\Inventory\Enums\CustomerReturnStatus;
use Modules\Inventory\Enums\InvoiceStatus;
use Modules\Inventory\Models\Batch;
use Modules\Inventory\Models\CustomerCreditNote;
use Modules\Inventory\Models\CustomerReturn;
use Modules\Inventory\Models\CustomerReturnItem;
use Modules\Inventory\Models\CustomerReturnPiece;
use Modules\Inventory\Models\DeliveryOrderItem;
use Modules\Inventory\Models\DeliveryOrderPiece;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\Invoice;
use Modules\Inventory\Models\InvoiceItem;
use Modules\Inventory\Models\ProductLocationStore;
use Modules\Inventory\Models\SalesOrderItem;
use Modules\Inventory\Models\StockReferenceType;
use Modules\Inventory\Models\StockTransaction;
use Modules\Inventory\Models\Store;
use Modules\Inventory\Support\Quantity;

/**
 * Customer (sales) returns, raised against one issued or paid invoice: Draft → Confirm.
 *
 * Invoices never moved stock — the delivery order they bill did — so confirming a return
 * reverses that delivery: an inbound customer_return ledger row per line (per roll for
 * roll lines), priced at the same selling price the delivery went out at, into the store
 * the user chose. Delivered rolls come back through RollService::restoreReturned().
 *
 * The money side credits the customer exactly what the invoice charged for the returned
 * quantity: first against what the invoice still owes (applied_to_invoice — read by
 * InvoiceBalanceService), any excess (the invoice was already paid) as an open
 * sales_return credit note the customer can spend on a future receipt.
 *
 * Returnable quantity = invoiced − already returned on CONFIRMED returns. Drafts reserve
 * nothing; confirm() re-checks under the invoice row lock, so two drafts can never both
 * return the same goods.
 */
class CustomerReturnService
{
    public function __construct(
        private readonly UnitConversionService $units,
        private readonly RollService $rolls,
        private readonly InvoiceBalanceService $balances,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = CustomerReturn::with([
            'customer:id,customer_code,customer_name',
            'invoice:id,invoice_no',
            'store:id,store_code,store_name',
        ])->orderByDesc('return_date')->orderByDesc('id');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term): void {
                $q->where('return_no', 'like', $term)
                  ->orWhereHas('invoice', fn ($i) => $i->where('invoice_no', 'like', $term));
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if (!empty($filters['invoice_id'])) {
            $query->where('invoice_id', (int) $filters['invoice_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('return_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('return_date', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    public function find(int $id): CustomerReturn
    {
        return CustomerReturn::with([
            'customer:id,customer_code,customer_name',
            'invoice:id,invoice_no,invoice_date,grand_total,status',
            'deliveryOrder:id,do_no',
            'store:id,store_code,store_name',
            'creditNote:id,credit_note_no,amount,remaining_balance,status',
            'createdBy:id,name',
            'confirmedBy:id,name',
            'items.product:id,product_code,name',
            'items.attribute:id,attribute_name',
            'items.unit:id,name,symbol',
            'items.store:id,store_code,store_name',
            'items.pieces.restoredPiece:id,piece_code',
        ])->findOrFail($id);
    }

    /**
     * Issued/paid invoices of a customer that still have something left to return —
     * the invoice picker on the return form.
     *
     * @return array<int, array<string, mixed>>
     */
    public function returnableInvoices(int $customerId): array
    {
        $invoices = Invoice::with('deliveryOrder:id,do_no')
            ->where('customer_id', $customerId)
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Paid->value])
            ->whereNotNull('do_id')
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->get(['id', 'invoice_no', 'invoice_date', 'do_id', 'grand_total', 'status']);

        if ($invoices->isEmpty()) {
            return [];
        }

        $invoiceIds = $invoices->pluck('id')->all();

        $invoicedQty = InvoiceItem::whereIn('invoice_id', $invoiceIds)
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(quantity) as qty')
            ->pluck('qty', 'invoice_id');

        $returnedQty = DB::table('inv_customer_return_items as ri')
            ->join('inv_customer_returns as r', 'r.id', '=', 'ri.return_id')
            ->whereIn('r.invoice_id', $invoiceIds)
            ->where('r.status', CustomerReturnStatus::Confirmed->value)
            ->whereNull('r.deleted_at')
            ->groupBy('r.invoice_id')
            ->selectRaw('r.invoice_id, SUM(ri.quantity) as qty')
            ->pluck('qty', 'invoice_id');

        $received = $this->balances->receivedFor($invoiceIds);
        $returned = $this->balances->returnedFor($invoiceIds);

        return $invoices
            ->filter(fn (Invoice $i) => (float) ($invoicedQty[$i->id] ?? 0) - (float) ($returnedQty[$i->id] ?? 0) > Quantity::EPSILON)
            ->map(fn (Invoice $i) => [
                'invoice_id'   => $i->id,
                'invoice_no'   => $i->invoice_no,
                'invoice_date' => $i->invoice_date?->toDateString(),
                'do_no'        => $i->deliveryOrder?->do_no,
                'status'       => $i->status->value,
                'status_label' => $i->status->label(),
                'grand_total'  => (float) $i->grand_total,
                'outstanding'  => max(0.0, (float) $i->grand_total - ($received[$i->id] ?? 0.0) - ($returned[$i->id] ?? 0.0)),
            ])
            ->values()
            ->all();
    }

    /**
     * Everything the return form needs to show one invoice's lines: what was billed,
     * what has already come back, what can still come back — and for roll lines, each
     * delivered roll with the same three figures. Quantities are in the invoice line's UOM.
     *
     * @return array<string, mixed>
     */
    public function returnableItems(int $invoiceId): array
    {
        $invoice = Invoice::with([
            'customer:id,customer_code,customer_name',
            'deliveryOrder:id,do_no,store_id,location_id',
            'items.product:id,product_code,name,base_unit_type_id',
            'items.attribute:id,attribute_name',
            'items.unit:id,name,symbol',
        ])->findOrFail($invoiceId);

        $this->assertReturnable($invoice);

        $lines = $this->returnableLines($invoice);

        return [
            'invoice' => [
                'id'               => $invoice->id,
                'invoice_no'       => $invoice->invoice_no,
                'invoice_date'     => $invoice->invoice_date?->toDateString(),
                'status'           => $invoice->status->value,
                'status_label'     => $invoice->status->label(),
                'grand_total'      => (float) $invoice->grand_total,
                'outstanding'      => max(0.0, $this->balances->outstanding($invoice->id)),
                'customer_id'      => $invoice->customer_id,
                'customer'         => $invoice->customer ? [
                    'id'            => $invoice->customer->id,
                    'customer_code' => $invoice->customer->customer_code,
                    'name'          => $invoice->customer->customer_name,
                ] : null,
                'do_id'            => $invoice->do_id,
                'do_no'            => $invoice->deliveryOrder?->do_no,
                // Where the goods originally left from — the natural place for them to go back.
                'default_store_id' => $this->originalStoreId($invoice),
            ],
            'items' => array_values(array_map(fn (array $line) => [
                'invoice_item_id' => $line['item']->id,
                'do_item_id'      => $line['item']->do_item_id,
                'product_id'      => $line['item']->product_id,
                'product_code'    => $line['item']->product?->product_code,
                'product_name'    => $line['item']->product?->name,
                'attribute_name'  => $line['item']->attribute?->attribute_name,
                'unit_name'       => $line['item']->unit?->symbol ?: $line['item']->unit?->name,
                'is_scanned'      => $line['is_scanned'],
                'invoiced_qty'    => (float) $line['item']->quantity,
                'returned_qty'    => $line['returned'],
                'returnable_qty'  => $line['returnable'],
                'unit_price'      => (float) $line['item']->unit_price,
                'discount'        => (float) $line['item']->discount,
                'tax'             => (float) $line['item']->tax,
                'line_total'      => (float) $line['item']->line_total,
                'pieces'          => array_values(array_map(fn (array $piece) => [
                    'do_piece_id'    => $piece['do_piece']->id,
                    'piece_code'     => $piece['do_piece']->piece_code,
                    'taken_qty'      => $piece['taken'],
                    'returned_qty'   => $piece['returned'],
                    'returnable_qty' => $piece['returnable'],
                ], $line['pieces'])),
            ], $lines)),
        ];
    }

    public function create(CustomerReturnData $data): CustomerReturn
    {
        return DB::transaction(function () use ($data): CustomerReturn {
            $invoice = $this->invoiceFor($data);

            $return = CustomerReturn::create([
                'return_no'   => $this->generateReturnNo(),
                ...$this->headerAttributes($data, $invoice),
                'status'      => CustomerReturnStatus::Draft,
                'created_by'  => Auth::id(),
            ]);

            $this->syncItems($return, $invoice, $data);

            return $this->find($return->id);
        });
    }

    public function update(CustomerReturn $return, CustomerReturnData $data): CustomerReturn
    {
        if ($return->status !== CustomerReturnStatus::Draft) {
            abort(422, 'Only draft returns can be edited.');
        }

        return DB::transaction(function () use ($return, $data): CustomerReturn {
            $invoice = $this->invoiceFor($data);

            $return->update($this->headerAttributes($data, $invoice));
            $this->syncItems($return, $invoice, $data);

            return $this->find($return->id);
        });
    }

    public function delete(CustomerReturn $return): void
    {
        if ($return->status !== CustomerReturnStatus::Draft) {
            abort(422, 'Only draft returns can be deleted.');
        }

        DB::transaction(function () use ($return): void {
            $return->pieces()->delete();
            $return->items()->delete();
            $return->delete();
        });
    }

    /** Post the return: stock back in, rolls restored, customer credited — one transaction. */
    public function confirm(CustomerReturn $return): CustomerReturn
    {
        return DB::transaction(function () use ($return): CustomerReturn {
            $return = CustomerReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();

            if ($return->status !== CustomerReturnStatus::Draft) {
                abort(422, 'Only draft returns can be confirmed.');
            }

            // The invoice row lock serialises every confirm against this invoice, so the
            // returnable figures below cannot change underneath us.
            $invoice = Invoice::whereKey($return->invoice_id)->lockForUpdate()->firstOrFail();
            $invoice->load(['items.product', 'deliveryOrder']);
            $this->assertReturnable($invoice);

            $return->load(['items.pieces']);
            if ($return->items->isEmpty()) {
                abort(422, 'The return has no lines.');
            }

            $lines = collect($this->returnableLines($invoice))->keyBy(fn (array $line) => $line['item']->id);

            foreach ($return->items as $item) {
                $this->assertWithinReturnable($item, $lines->get($item->invoice_item_id));
            }

            foreach ($return->items as $item) {
                $line = $lines->get($item->invoice_item_id);

                $line['is_scanned']
                    ? $this->receiveRolls($return, $item, $line)
                    : $this->receiveManual($return, $item, $line);
            }

            $this->creditCustomer($return, $invoice);

            $return->update([
                'status'       => CustomerReturnStatus::Confirmed,
                'confirmed_at' => now(),
                'confirmed_by' => Auth::id(),
            ]);

            $this->balances->markPaidIfSettled([$invoice->id]);

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
     * Rebuild a draft's lines from the payload, pricing each one off the invoice line it
     * returns and checking it against what is still returnable.
     */
    private function syncItems(CustomerReturn $return, Invoice $invoice, CustomerReturnData $data): void
    {
        $return->pieces()->delete();
        $return->items()->delete();

        $invoice->loadMissing(['items.product', 'deliveryOrder']);
        $lines  = collect($this->returnableLines($invoice))->keyBy(fn (array $line) => $line['item']->id);
        $stores = Store::whereIn('id', collect($data->items)->pluck('store_id')->filter()->push($data->storeId)->unique())
            ->get(['id', 'location_id'])
            ->keyBy('id');

        $total = 0.0;

        foreach ($data->items as $row) {
            $line = $lines->get((int) $row['invoice_item_id']);
            abort_if($line === null, 422, 'One of the lines does not belong to the selected invoice.');

            /** @var InvoiceItem $invoiceItem */
            $invoiceItem = $line['item'];
            $store       = $stores->get((int) ($row['store_id'] ?? $data->storeId));
            $factor      = $line['factor'];

            [$quantity, $pieceRows] = $line['is_scanned']
                ? $this->pieceRowsFor($line, (array) ($row['pieces'] ?? []), $factor)
                : [Quantity::round((float) ($row['quantity'] ?? 0)), []];

            abort_if(
                ! Quantity::isPositive($quantity),
                422,
                "Enter the quantity being returned for {$invoiceItem->product?->name}.",
            );

            $lineTotal = $this->lineValue($invoiceItem, $quantity);
            $total    += $lineTotal;

            $item = CustomerReturnItem::create([
                'return_id'       => $return->id,
                'invoice_item_id' => $invoiceItem->id,
                'do_item_id'      => $invoiceItem->do_item_id,
                'product_id'      => $invoiceItem->product_id,
                'attribute_id'    => $invoiceItem->attribute_id,
                'unit_id'         => $invoiceItem->unit_id,
                'quantity'        => $quantity,
                'base_quantity'   => Quantity::round($quantity * $factor),
                'unit_price'      => $invoiceItem->unit_price,
                'discount'        => $invoiceItem->discount,
                'tax'             => $invoiceItem->tax,
                'line_total'      => $lineTotal,
                'store_id'        => $store->id,
                'location_id'     => $store->location_id,
                'condition'       => $row['condition'],
                'reason'          => $row['reason'],
                'remarks'         => $row['remarks'] ?? null,
            ]);

            $this->assertWithinReturnable($item, $line, $pieceRows);

            foreach ($pieceRows as $pieceRow) {
                CustomerReturnPiece::create([
                    'return_id'      => $return->id,
                    'return_item_id' => $item->id,
                    ...$pieceRow,
                ]);
            }
        }

        $return->update(['total_amount' => round($total, 2)]);
    }

    /**
     * Per-roll quantities for a roll line, keyed to the delivered rolls on its DO line.
     *
     * @param  array<string, mixed>                               $line
     * @param  array<array{do_piece_id:int|string, quantity:float|string}> $pieces
     * @return array{0: float, 1: array<int, array<string, mixed>>} [line quantity, piece rows]
     */
    private function pieceRowsFor(array $line, array $pieces, float $factor): array
    {
        abort_if(empty($pieces), 422, 'Select the rolls that came back for ' . ($line['item']->product?->name ?? 'this line') . '.');

        $delivered = collect($line['pieces'])->keyBy(fn (array $p) => $p['do_piece']->id);
        $rows      = [];
        $quantity  = 0.0;

        foreach ($pieces as $piece) {
            $known = $delivered->get((int) $piece['do_piece_id']);
            abort_if($known === null, 422, 'One of the selected rolls was not delivered on this invoice line.');

            $qty     = Quantity::round((float) $piece['quantity']);
            $baseQty = Quantity::round($qty * $factor);

            // Returning everything that is left on the roll must land exactly on what the
            // delivery took, not on a UOM round trip of it (2 yd -> 1.828801 m while the
            // roll gave 1.828799 m) — otherwise a whole roll comes back as a cut one.
            if (abs($qty - $known['returnable']) <= Quantity::toleranceFor($factor)) {
                $qty     = $known['returnable'];
                $baseQty = Quantity::round((float) $known['do_piece']->taken_quantity - $known['returned'] * $factor);
            }

            $quantity += $qty;

            $rows[] = [
                'do_piece_id'       => $known['do_piece']->id,
                'piece_id'          => $known['do_piece']->piece_id,
                'piece_code'        => $known['do_piece']->piece_code,
                'quantity'          => $qty,
                'returned_quantity' => $baseQty,
            ];
        }

        return [Quantity::round($quantity), $rows];
    }

    /**
     * Each invoice line with its returnable figures (line UOM). Roll lines also list the
     * rolls their DO line delivered.
     *
     * @return array<int, array{item: InvoiceItem, is_scanned: bool, factor: float, returned: float, returnable: float, pieces: array<int, array<string, mixed>>}>
     */
    private function returnableLines(Invoice $invoice): array
    {
        $invoice->loadMissing('items.product');

        $doItems = DeliveryOrderItem::whereIn('id', $invoice->items->pluck('do_item_id')->filter())
            ->get(['id', 'is_scanned'])
            ->keyBy('id');

        $doPieces = DeliveryOrderPiece::whereIn('do_item_id', $doItems->keys())
            ->orderBy('id')
            ->get()
            ->groupBy('do_item_id');

        $confirmed = fn ($q) => $q->where('status', CustomerReturnStatus::Confirmed->value);

        $returnedByItem = CustomerReturnItem::whereIn('invoice_item_id', $invoice->items->pluck('id'))
            ->whereHas('customerReturn', $confirmed)
            ->groupBy('invoice_item_id')
            ->selectRaw('invoice_item_id, SUM(quantity) as qty')
            ->pluck('qty', 'invoice_item_id');

        $returnedByPiece = CustomerReturnPiece::whereIn('do_piece_id', $doPieces->flatten()->pluck('id'))
            ->whereHas('item.customerReturn', $confirmed)
            ->groupBy('do_piece_id')
            ->selectRaw('do_piece_id, SUM(quantity) as qty')
            ->pluck('qty', 'do_piece_id');

        $lines = [];

        foreach ($invoice->items as $item) {
            $isScanned = (bool) ($doItems->get($item->do_item_id)?->is_scanned ?? false);
            $factor    = $this->lineFactor($item);
            $returned  = (float) ($returnedByItem[$item->id] ?? 0);

            $pieces = [];
            if ($isScanned) {
                foreach ($doPieces->get($item->do_item_id, collect()) as $doPiece) {
                    // taken_quantity is in the stocking UOM; restate it in the line's UOM.
                    $taken        = Quantity::round((float) $doPiece->taken_quantity / $factor);
                    $pieceReturned = (float) ($returnedByPiece[$doPiece->id] ?? 0);

                    $pieces[] = [
                        'do_piece'   => $doPiece,
                        'taken'      => $taken,
                        'returned'   => $pieceReturned,
                        'returnable' => max(0.0, Quantity::round($taken - $pieceReturned)),
                    ];
                }
            }

            $lines[] = [
                'item'       => $item,
                'is_scanned' => $isScanned,
                'factor'     => $factor,
                'returned'   => $returned,
                'returnable' => max(0.0, Quantity::round((float) $item->quantity - $returned)),
                'pieces'     => $pieces,
            ];
        }

        return $lines;
    }

    /**
     * @param array<string, mixed>|null              $line
     * @param array<int, array<string, mixed>>|null  $pieceRows  draft rows not yet saved; defaults to the item's saved pieces
     */
    private function assertWithinReturnable(CustomerReturnItem $item, ?array $line, ?array $pieceRows = null): void
    {
        abort_if($line === null, 422, 'One of the lines no longer exists on the invoice.');

        $name      = $line['item']->product?->name ?? "line #{$item->invoice_item_id}";
        $tolerance = Quantity::toleranceFor($line['factor']);

        abort_if(
            (float) $item->quantity - $line['returnable'] > $tolerance,
            422,
            sprintf(
                'Cannot return %s of %s — only %s is still returnable.',
                Quantity::format((float) $item->quantity),
                $name,
                Quantity::format($line['returnable']),
            ),
        );

        $pieces    = collect($line['pieces'])->keyBy(fn (array $p) => $p['do_piece']->id);
        $pieceRows ??= $item->pieces->map(fn (CustomerReturnPiece $p) => $p->only(['do_piece_id', 'piece_code', 'quantity']))->all();

        foreach ($pieceRows as $row) {
            $piece = $pieces->get((int) $row['do_piece_id']);

            abort_if(
                $piece === null || (float) $row['quantity'] - $piece['returnable'] > $tolerance,
                422,
                sprintf(
                    'Roll %s: only %s is still returnable.',
                    $row['piece_code'],
                    Quantity::format($piece['returnable'] ?? 0.0),
                ),
            );
        }
    }

    // ── Posting ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $line */
    private function receiveManual(CustomerReturn $return, CustomerReturnItem $item, array $line): void
    {
        $this->postInbound($return, $item, $line, (float) $item->base_quantity, (float) $item->quantity, null);
    }

    /**
     * Each returned roll is received on its own ledger row, so the row can carry the
     * roll's batch and the restored roll can point at the row that brought it back.
     *
     * @param array<string, mixed> $line
     */
    private function receiveRolls(CustomerReturn $return, CustomerReturnItem $item, array $line): void
    {
        $delivered = collect($line['pieces'])->keyBy(fn (array $p) => $p['do_piece']->id);

        foreach ($item->pieces as $returnPiece) {
            /** @var DeliveryOrderPiece $doPiece */
            $doPiece = $delivered->get($returnPiece->do_piece_id)['do_piece'];
            $roll    = GrnItemPiece::whereKey($doPiece->piece_id)->lockForUpdate()->firstOrFail();

            $txn = $this->postInbound(
                $return,
                $item,
                $line,
                (float) $returnPiece->returned_quantity,
                (float) $returnPiece->quantity,
                $doPiece->batch_id,
            );

            $restored = $this->rolls->restoreReturned(
                $roll,
                (float) $returnPiece->returned_quantity,
                (float) $doPiece->taken_quantity,
                (int) $doPiece->sale_cycle,
                $item->store_id,
                $item->location_id,
            );

            $returnPiece->update([
                'restored_piece_id'    => $restored->id,
                'store_id'             => $item->store_id,
                'location_id'          => $item->location_id,
                'batch_id'             => $doPiece->batch_id,
                'stock_transaction_id' => $txn->id,
            ]);
        }
    }

    /**
     * One inbound ledger row + the store balance (and batch) it lands in.
     *
     * @param array<string, mixed> $line
     */
    private function postInbound(
        CustomerReturn $return,
        CustomerReturnItem $item,
        array $line,
        float $baseQty,
        float $enteredQty,
        ?int $batchId,
    ): StockTransaction {
        $batch = $batchId ? Batch::find($batchId) : null;

        $txn = StockTransaction::create([
            'transaction_date' => now(),
            'reference_type'   => StockReferenceType::CODE_CUSTOMER_RETURN,
            'reference_id'     => $return->id,
            'product_id'       => $item->product_id,
            'attribute_id'     => $item->attribute_id,
            'store_id'         => $item->store_id,
            'location_id'      => $item->location_id,
            'batch_no'         => $batch?->batch_no,
            'batch_id'         => $batch?->id,
            'expiry_date'      => $batch?->expiry_date,
            'qty_in'           => $baseQty,
            'qty_out'          => 0,
            'unit_id'          => $this->units->baseUnitIdFor($line['item']->product),
            'entered_unit_id'  => $item->unit_id,
            'entered_qty'      => $enteredQty,
            'unit_price'       => $this->deliveryPrice($line['item'], $line['factor']),
            'created_by'       => Auth::id(),
        ]);

        $pivot = ProductLocationStore::firstOrCreate(
            [
                'product_id'  => $item->product_id,
                'store_id'    => $item->store_id,
                'location_id' => $item->location_id,
            ],
            ['current_stock' => 0],
        );
        ProductLocationStore::whereKey($pivot->id)->lockForUpdate()->first()->increment('current_stock', $baseQty);

        $batch?->increment('current_qty', $baseQty);

        return $txn;
    }

    /**
     * Apply the return's value to the invoice's outstanding first; whatever the invoice no
     * longer owes (it was already paid) becomes an open sales_return credit note.
     */
    private function creditCustomer(CustomerReturn $return, Invoice $invoice): void
    {
        $total       = round((float) $return->total_amount, 2);
        $outstanding = max(0.0, round($this->balances->outstanding($invoice->id), 2));
        $applied     = min($total, $outstanding);
        $excess      = round($total - $applied, 2);

        $creditNoteId = null;

        if ($excess > 0.01) {
            $creditNoteId = CustomerCreditNote::create([
                'credit_note_no'    => $this->balances->generateCreditNoteNo(),
                'customer_id'       => $return->customer_id,
                'credit_type'       => CustomerCreditNoteType::SalesReturn,
                'amount'            => $excess,
                'remaining_balance' => $excess,
                'remark'            => "Sales return {$return->return_no} against invoice {$invoice->invoice_no}.",
                'status'            => CreditNoteStatus::Open,
                'source_return_id'  => $return->id,
                'created_by'        => Auth::id(),
            ])->id;
        }

        $return->update([
            'applied_to_invoice' => $applied,
            'credit_note_id'     => $creditNoteId,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** The invoice line's value for $quantity of it — exactly what was charged, pro rata. */
    private function lineValue(InvoiceItem $item, float $quantity): float
    {
        $invoiced = (float) $item->quantity;

        return $invoiced > 0 ? round((float) $item->line_total * $quantity / $invoiced, 2) : 0.0;
    }

    /** Line UOM -> stocking UOM. */
    private function lineFactor(InvoiceItem $item): float
    {
        $baseUnitId = $this->units->baseUnitIdFor($item->product);

        return $this->units->factor($item->unit_id ?? $baseUnitId, $baseUnitId) ?: 1.0;
    }

    /**
     * The base-UOM selling price the delivery posted its qty_out at (DeliveryOrderService
     * rebases the SO line price the same way) — so the return reverses the same value.
     */
    private function deliveryPrice(InvoiceItem $item, float $factor): float
    {
        $soPrice = (float) (SalesOrderItem::whereKey($item->so_item_id)->value('unit_price') ?? 0);

        return $factor > 0 ? Quantity::roundPrice($soPrice / $factor) : 0.0;
    }

    /** The store the delivery shipped from: the DO's own store, else the first roll's. */
    private function originalStoreId(Invoice $invoice): ?int
    {
        $do = $invoice->deliveryOrder;

        if ($do?->store_id) {
            return (int) $do->store_id;
        }

        $storeId = DeliveryOrderPiece::where('do_id', $invoice->do_id)->orderBy('id')->value('store_id');

        return $storeId ? (int) $storeId : null;
    }

    private function assertReturnable(Invoice $invoice): void
    {
        abort_unless(
            in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Paid], true),
            422,
            "Invoice {$invoice->invoice_no} is {$invoice->status->label()} — only issued or paid invoices can be returned against.",
        );

        abort_if($invoice->do_id === null, 422, "Invoice {$invoice->invoice_no} has no delivery order — there is no stock to return.");
    }

    private function invoiceFor(CustomerReturnData $data): Invoice
    {
        $invoice = Invoice::with(['items.product', 'deliveryOrder'])->findOrFail($data->invoiceId);

        abort_if($invoice->customer_id !== $data->customerId, 422, 'The selected invoice does not belong to this customer.');
        $this->assertReturnable($invoice);

        return $invoice;
    }

    /** @return array<string, mixed> */
    private function headerAttributes(CustomerReturnData $data, Invoice $invoice): array
    {
        return [
            'return_date' => $data->returnDate,
            'customer_id' => $data->customerId,
            'invoice_id'  => $invoice->id,
            'do_id'       => $invoice->do_id,
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
        $prefix = 'SRN-';

        $last = CustomerReturn::withTrashed()
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
