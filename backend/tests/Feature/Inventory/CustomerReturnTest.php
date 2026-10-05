<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\CustomerCreditNote;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Models\CustomerReturn;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GoodsReceivedNoteItem;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\Invoice;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductLocationStore;
use Modules\Inventory\Models\SalesOrder;
use Modules\Inventory\Models\StockReferenceType;
use Modules\Inventory\Models\StockTransaction;
use Modules\Inventory\Services\CustomerReceiptService;
use Modules\Inventory\Services\OutstandingSummaryReportService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Customer returns: pick a customer's issued/paid invoice, return part of what it billed
 * (whole or partial rolls, or a typed quantity), optionally into another store. Confirm
 * puts the stock back and credits the customer — against the invoice's outstanding first,
 * any excess as an open sales_return credit note.
 */
class CustomerReturnTest extends TestCase
{
    use RefreshDatabase;

    private const RETURN_PERMISSIONS = [
        'view_customer_returns', 'create_customer_returns', 'edit_customer_returns',
        'delete_customer_returns', 'confirm_customer_returns',
    ];

    private User $user;
    private CustomerMaster $customer;
    private int $storeId;
    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view_sales_orders', 'create_sales_orders',
            'view_delivery_orders', 'create_delivery_orders', 'edit_delivery_orders',
            'view_invoices', 'create_invoices', 'edit_invoices',
            ...self::RETURN_PERMISSIONS,
        ];
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo($permissions);

        $this->customer = CustomerMaster::create([
            'customer_code' => 'CUS-0001',
            'customer_name' => 'Test Customer',
            'customer_type' => 'Retail',
        ]);

        [$this->storeId, $this->locationId] = $this->makeStoreAndLocation();
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /** @return array{0: int, 1: int} [storeId, locationId] */
    private function makeStoreAndLocation(): array
    {
        static $seq = 0;
        $seq++;

        $locationId = DB::table('inv_locations')->insertGetId([
            'company_id'             => 1,
            'industry_id'            => 1,
            'location_code'          => "LOC-R{$seq}",
            'location_name'          => "Return Location {$seq}",
            'country'                => 'LK',
            'loc_street_address'     => 'Street',
            'loc_city'               => 'City',
            'loc_country'            => 'LK',
            'loc_state'              => 'State',
            'loc_postal_zip_code'    => '00000',
            'financial_year'         => '2026',
            'stock_releasing_method' => 'FIFO',
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        $storeId = DB::table('inv_stores')->insertGetId([
            'store_type_id' => 1,
            'location_id'   => $locationId,
            'store_code'    => "STR-R{$seq}",
            'store_name'    => "Return Store {$seq}",
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return [$storeId, $locationId];
    }

    /**
     * Sell rolls of a fresh product (taking $takes off each, or the whole roll), deliver
     * them and issue the invoice.
     *
     * @param  array<float>      $weights
     * @param  array<float>|null $takes   per-roll quantity sold; null sells every roll whole
     * @return array{invoice: Invoice, product: Product, rolls: array<GrnItemPiece>}
     */
    private function invoicedRollSale(array $weights, ?array $takes = null, float $unitPrice = 100.0): array
    {
        static $seq = 0;
        $seq++;

        $product = Product::factory()->create();

        $grn = GoodsReceivedNote::create([
            'grn_no'      => sprintf('GRN-RET-%04d', $seq),
            'grn_date'    => '2026-07-01',
            'status'      => 'confirmed',
            'store_id'    => $this->storeId,
            'location_id' => $this->locationId,
        ]);
        $grnItem = GoodsReceivedNoteItem::create([
            'grn_id'            => $grn->id,
            'product_id'        => $product->id,
            'quantity_received' => array_sum($weights),
            'unit_price'        => 40,
        ]);

        $rolls = [];
        foreach ($weights as $i => $weight) {
            $rolls[] = GrnItemPiece::create([
                'grn_item_id' => $grnItem->id,
                'grn_id'      => $grn->id,
                'product_id'  => $product->id,
                'store_id'    => $this->storeId,
                'location_id' => $this->locationId,
                'piece_no'    => $i + 1,
                'weight'      => $weight,
                'piece_code'  => sprintf('%s-I001-P%03d', $grn->grn_no, $i + 1),
                'status'      => GrnItemPiece::STATUS_IN_STOCK,
            ]);
        }

        ProductLocationStore::create([
            'product_id'    => $product->id,
            'store_id'      => $this->storeId,
            'location_id'   => $this->locationId,
            'current_stock' => array_sum($weights),
        ]);

        $line = [
            'product_id'  => $product->id,
            'unit_price'  => $unitPrice,
            'piece_codes' => array_map(fn (GrnItemPiece $r) => $r->piece_code, $rolls),
        ];
        if ($takes !== null) {
            $line['quantity']    = array_sum($takes);
            $line['piece_takes'] = array_combine(array_map(fn (GrnItemPiece $r) => $r->piece_code, $rolls), $takes);
        }

        $so = $this->confirmedSo([$line]);

        $doId = $this->confirmedDo($so, [[
            'so_item_id' => $so->items()->sole()->id,
            'piece_ids'  => array_map(fn (GrnItemPiece $r) => $r->id, $rolls),
        ]]);

        return ['invoice' => $this->issuedInvoice($doId), 'product' => $product, 'rolls' => $rolls];
    }

    /**
     * Sell a typed quantity of a roll-less product from the default store, deliver and invoice it.
     *
     * @return array{invoice: Invoice, product: Product}
     */
    private function invoicedManualSale(float $qty, float $unitPrice = 50.0): array
    {
        $product = Product::factory()->create();

        // A location-less pool satisfies the SO-stage stock check; the DO ships from the store.
        ProductLocationStore::create(['product_id' => $product->id, 'store_id' => null, 'location_id' => null, 'current_stock' => 100000]);
        ProductLocationStore::create(['product_id' => $product->id, 'store_id' => $this->storeId, 'location_id' => $this->locationId, 'current_stock' => 100]);

        $so = $this->confirmedSo([[
            'product_id' => $product->id,
            'quantity'   => $qty,
            'unit_price' => $unitPrice,
        ]]);

        $doId = $this->confirmedDo(
            $so,
            [['so_item_id' => $so->items()->sole()->id, 'quantity' => $qty]],
            ['store_id' => $this->storeId, 'location_id' => $this->locationId],
        );

        return ['invoice' => $this->issuedInvoice($doId), 'product' => $product];
    }

    /** @param array<int, array<string, mixed>> $items */
    private function confirmedSo(array $items): SalesOrder
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/sales-orders', [
            'order_date'      => '2026-07-09',
            'customer_id'     => $this->customer->id,
            'sales_person_id' => $this->user->id,
            'status'          => 'confirmed',
            'items'           => $items,
        ]);
        $response->assertCreated();

        return SalesOrder::findOrFail($response->json('data.id'));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed>             $extra
     */
    private function confirmedDo(SalesOrder $so, array $items, array $extra = []): int
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/delivery-orders', [
            'so_id'         => $so->id,
            'delivery_date' => '2026-07-10',
            'items'         => $items,
            ...$extra,
        ]);
        $response->assertCreated();

        $doId = (int) $response->json('data.id');

        $this->actingAs($this->user)
            ->patchJson("/api/v1/delivery-orders/{$doId}/status", ['status' => 'confirmed'])
            ->assertOk();

        return $doId;
    }

    private function issuedInvoice(int $doId): Invoice
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
            'do_id'        => $doId,
            'invoice_date' => '2026-07-11',
            'invoice_type' => 'non_tax',
        ]);
        $response->assertCreated();

        $invoiceId = (int) $response->json('data.id');

        $this->actingAs($this->user)
            ->patchJson("/api/v1/invoices/{$invoiceId}/status", ['status' => 'issued'])
            ->assertOk();

        return Invoice::with('items')->findOrFail($invoiceId);
    }

    /** Record a confirmed receipt of $amount against the invoice (bypasses the receipt form). */
    private function receive(Invoice $invoice, float $amount): void
    {
        static $seq = 0;
        $seq++;

        $receiptId = DB::table('inv_customer_receipts')->insertGetId([
            'receipt_no'   => sprintf('RCP-T%04d', $seq),
            'receipt_date' => '2026-07-12',
            'customer_id'  => $this->customer->id,
            'status'       => 'confirmed',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('inv_customer_receipt_allocations')->insert([
            'receipt_id'     => $receiptId,
            'reference_type' => 'invoice',
            'reference_id'   => $invoice->id,
            'receipt_amount' => $amount,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>> $items
     * @param  array<string, mixed>             $overrides
     * @return array<string, mixed>
     */
    private function payload(Invoice $invoice, array $items, array $overrides = []): array
    {
        return [
            'return_date' => '2026-07-15',
            'customer_id' => $this->customer->id,
            'invoice_id'  => $invoice->id,
            'store_id'    => $this->storeId,
            'items'       => array_map(fn (array $item) => [
                'invoice_item_id' => $invoice->items->first()->id,
                'reason'          => 'damaged',
                'condition'       => 'good',
                ...$item,
            ], $items),
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function createReturn(array $payload): int
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/customer-returns', $payload);
        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    private function confirm(int $returnId): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson("/api/v1/customer-returns/{$returnId}/confirm");
    }

    /** @return array<int> do_piece ids of the invoice's delivery, in roll order */
    private function doPieceIds(Invoice $invoice): array
    {
        return DB::table('inv_delivery_order_pieces')->where('do_id', $invoice->do_id)->orderBy('id')->pluck('id')->all();
    }

    private function stockAt(Product $product, int $storeId): float
    {
        return (float) ProductLocationStore::where('product_id', $product->id)->where('store_id', $storeId)->value('current_stock');
    }

    // ── Auth & permissions ──────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/customer-returns')->assertUnauthorized();
    }

    public function test_user_without_create_permission_is_forbidden(): void
    {
        $sale = $this->invoicedManualSale(10);
        $this->user->revokePermissionTo('create_customer_returns');

        $this->actingAs($this->user)
            ->postJson('/api/v1/customer-returns', $this->payload($sale['invoice'], [['quantity' => 2]]))
            ->assertForbidden();
    }

    public function test_user_without_confirm_permission_cannot_confirm(): void
    {
        $sale = $this->invoicedManualSale(10);
        $id   = $this->createReturn($this->payload($sale['invoice'], [['quantity' => 2]]));
        $this->user->revokePermissionTo('confirm_customer_returns');

        $this->confirm($id)->assertForbidden();
    }

    // ── Pickers ─────────────────────────────────────────────────────────────

    public function test_returnable_invoices_and_items_reflect_what_has_come_back(): void
    {
        $sale    = $this->invoicedManualSale(10);
        $invoice = $sale['invoice'];

        $this->actingAs($this->user)
            ->getJson("/api/v1/customer-returns/returnable-invoices/{$this->customer->id}")
            ->assertOk()
            ->assertJsonPath('data.0.invoice_id', $invoice->id);

        $this->confirm($this->createReturn($this->payload($invoice, [['quantity' => 4]])))->assertOk();

        $this->actingAs($this->user)
            ->getJson("/api/v1/customer-returns/returnable-items/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.invoice.default_store_id', $this->storeId)
            ->assertJsonPath('data.items.0.returned_qty', 4)
            ->assertJsonPath('data.items.0.returnable_qty', 6);

        // Fully returned → no longer offered.
        $this->confirm($this->createReturn($this->payload($invoice, [['quantity' => 6]])))->assertOk();

        $this->actingAs($this->user)
            ->getJson("/api/v1/customer-returns/returnable-invoices/{$this->customer->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ── Draft lifecycle ─────────────────────────────────────────────────────

    public function test_draft_is_numbered_priced_from_the_invoice_and_moves_no_stock(): void
    {
        $sale = $this->invoicedManualSale(10, 50);

        $this->actingAs($this->user)
            ->getJson('/api/v1/customer-returns/next-return-no')
            ->assertOk()
            ->assertJsonPath('data.return_no', 'SRN-0001');

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/customer-returns', $this->payload($sale['invoice'], [['quantity' => 3]], ['return_no' => 'HACK-1']));

        $response->assertCreated()
            ->assertJsonPath('data.return_no', 'SRN-0001')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_amount', 150)
            ->assertJsonPath('data.items.0.line_total', 150);

        $this->assertSame(0, StockTransaction::where('reference_type', StockReferenceType::CODE_CUSTOMER_RETURN)->count());
    }

    public function test_draft_can_be_edited_and_deleted_but_a_confirmed_return_cannot(): void
    {
        $sale = $this->invoicedManualSale(10);
        $id   = $this->createReturn($this->payload($sale['invoice'], [['quantity' => 2]]));

        $this->actingAs($this->user)
            ->putJson("/api/v1/customer-returns/{$id}", $this->payload($sale['invoice'], [['quantity' => 5]]))
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 5);

        $this->confirm($id)->assertOk();

        $this->actingAs($this->user)
            ->putJson("/api/v1/customer-returns/{$id}", $this->payload($sale['invoice'], [['quantity' => 1]]))
            ->assertStatus(422);
        $this->actingAs($this->user)->deleteJson("/api/v1/customer-returns/{$id}")->assertStatus(422);
        $this->confirm($id)->assertStatus(422);

        $draft = $this->createReturn($this->payload($sale['invoice'], [['quantity' => 1]]));
        $this->actingAs($this->user)->deleteJson("/api/v1/customer-returns/{$draft}")->assertNoContent();
        $this->assertSoftDeleted('inv_customer_returns', ['id' => $draft]);
    }

    public function test_invoice_of_another_customer_is_rejected(): void
    {
        $sale  = $this->invoicedManualSale(10);
        $other = CustomerMaster::create(['customer_code' => 'CUS-0002', 'customer_name' => 'Other', 'customer_type' => 'Retail']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/customer-returns', $this->payload($sale['invoice'], [['quantity' => 1]], ['customer_id' => $other->id]))
            ->assertStatus(422);
    }

    // ── Quantity limits ─────────────────────────────────────────────────────

    public function test_cannot_return_more_than_was_invoiced(): void
    {
        $sale = $this->invoicedManualSale(10);

        $this->actingAs($this->user)
            ->postJson('/api/v1/customer-returns', $this->payload($sale['invoice'], [['quantity' => 11]]))
            ->assertStatus(422);
    }

    public function test_two_drafts_cannot_both_return_the_same_goods(): void
    {
        $sale = $this->invoicedManualSale(10);

        $first  = $this->createReturn($this->payload($sale['invoice'], [['quantity' => 7]]));
        $second = $this->createReturn($this->payload($sale['invoice'], [['quantity' => 7]]));

        $this->confirm($first)->assertOk();
        $this->confirm($second)->assertStatus(422);

        $this->assertSame('draft', CustomerReturn::find($second)->status->value);
    }

    // ── Stock ───────────────────────────────────────────────────────────────

    public function test_manual_line_return_posts_stock_back_into_the_chosen_store(): void
    {
        $sale = $this->invoicedManualSale(10, 50);
        [$damagedStore] = $this->makeStoreAndLocation();

        $this->assertEqualsWithDelta(90.0, $this->stockAt($sale['product'], $this->storeId), 0.0001);

        $id = $this->createReturn($this->payload($sale['invoice'], [
            ['quantity' => 4, 'condition' => 'damaged', 'store_id' => $damagedStore],
        ]));
        $this->confirm($id)->assertOk()->assertJsonPath('data.status', 'confirmed');

        $txn = StockTransaction::where('reference_type', StockReferenceType::CODE_CUSTOMER_RETURN)->sole();
        $this->assertSame($id, (int) $txn->reference_id);
        $this->assertSame($damagedStore, (int) $txn->store_id);
        $this->assertEqualsWithDelta(4.0, (float) $txn->qty_in, 0.0001);
        $this->assertEqualsWithDelta(50.0, (float) $txn->unit_price, 0.0001);

        $this->assertEqualsWithDelta(90.0, $this->stockAt($sale['product'], $this->storeId), 0.0001);
        $this->assertEqualsWithDelta(4.0, $this->stockAt($sale['product'], $damagedStore), 0.0001);
    }

    public function test_a_whole_roll_returned_whole_goes_back_in_stock_under_its_own_code(): void
    {
        $sale = $this->invoicedRollSale([10.0, 20.0]);
        [$first] = $sale['rolls'];

        $this->assertSame(GrnItemPiece::STATUS_DELIVERED, $first->fresh()->status);

        $id = $this->createReturn($this->payload($sale['invoice'], [[
            'pieces' => [['do_piece_id' => $this->doPieceIds($sale['invoice'])[0], 'quantity' => 10]],
        ]]));
        $this->confirm($id)->assertOk()->assertJsonPath('data.items.0.pieces.0.restored_piece_code', $first->piece_code);

        $first->refresh();
        $this->assertSame(GrnItemPiece::STATUS_IN_STOCK, $first->status);
        $this->assertSame($this->storeId, $first->store_id);
        $this->assertSame(1, GrnItemPiece::where('product_id', $sale['product']->id)->where('status', GrnItemPiece::STATUS_IN_STOCK)->count());
        $this->assertEqualsWithDelta(10.0, $this->stockAt($sale['product'], $this->storeId), 0.0001);
    }

    public function test_a_roll_returned_whole_can_be_sold_delivered_and_returned_again(): void
    {
        $sale = $this->invoicedRollSale([10.0]);
        [$roll] = $sale['rolls'];

        $this->confirm($this->createReturn($this->payload($sale['invoice'], [[
            'pieces' => [['do_piece_id' => $this->doPieceIds($sale['invoice'])[0], 'quantity' => 10]],
        ]])))->assertOk();

        $this->assertSame(1, $roll->fresh()->sale_cycle);

        // Sold again under the same code — the one-sale-per-roll rule applies per cycle.
        $so = $this->confirmedSo([[
            'product_id'  => $sale['product']->id,
            'unit_price'  => 120,
            'piece_codes' => [$roll->piece_code],
        ]]);
        $this->assertSame(1, (int) DB::table('inv_sales_order_pieces')->where('so_id', $so->id)->value('sale_cycle'));

        // The delivery picker offers it again …
        $this->actingAs($this->user)
            ->getJson("/api/v1/delivery-orders/from-so/{$so->id}")
            ->assertOk()
            ->assertJsonPath('data.items.0.available_pieces.0.piece_id', $roll->id);

        // … it can be delivered and invoiced again …
        $resale = $this->issuedInvoice($this->confirmedDo($so, [[
            'so_item_id' => $so->items()->sole()->id,
            'piece_ids'  => [$roll->id],
        ]]));
        $this->assertSame(GrnItemPiece::STATUS_DELIVERED, $roll->fresh()->status);

        // … and come back once more, still under its own code.
        $this->confirm($this->createReturn($this->payload($resale, [[
            'pieces' => [['do_piece_id' => $this->doPieceIds($resale)[0], 'quantity' => 10]],
        ]])))->assertOk();

        $roll->refresh();
        $this->assertSame(GrnItemPiece::STATUS_IN_STOCK, $roll->status);
        $this->assertSame(2, $roll->sale_cycle);
        $this->assertFalse(GrnItemPiece::where('parent_piece_id', $roll->id)->exists());
        $this->assertEqualsWithDelta(10.0, $this->stockAt($sale['product'], $this->storeId), 0.0001);
    }

    public function test_the_old_invoice_cannot_return_a_roll_that_has_since_been_resold(): void
    {
        $sale    = $this->invoicedRollSale([10.0]);
        [$roll]  = $sale['rolls'];
        $doPiece = $this->doPieceIds($sale['invoice'])[0];

        $this->confirm($this->createReturn($this->payload($sale['invoice'], [[
            'pieces' => [['do_piece_id' => $doPiece, 'quantity' => 10]],
        ]])))->assertOk();

        $so = $this->confirmedSo([['product_id' => $sale['product']->id, 'unit_price' => 120, 'piece_codes' => [$roll->piece_code]]]);
        $this->issuedInvoice($this->confirmedDo($so, [['so_item_id' => $so->items()->sole()->id, 'piece_ids' => [$roll->id]]]));

        $this->actingAs($this->user)
            ->postJson('/api/v1/customer-returns', $this->payload($sale['invoice'], [[
                'pieces' => [['do_piece_id' => $doPiece, 'quantity' => 10]],
            ]]))
            ->assertStatus(422);

        $this->assertSame(GrnItemPiece::STATUS_DELIVERED, $roll->fresh()->status);
    }

    public function test_a_roll_still_cannot_sit_on_two_sales_orders_in_the_same_cycle(): void
    {
        $sale = $this->invoicedRollSale([10.0]);
        [$roll] = $sale['rolls'];

        $this->confirm($this->createReturn($this->payload($sale['invoice'], [[
            'pieces' => [['do_piece_id' => $this->doPieceIds($sale['invoice'])[0], 'quantity' => 10]],
        ]])))->assertOk();

        $line = ['product_id' => $sale['product']->id, 'unit_price' => 120, 'piece_codes' => [$roll->piece_code]];
        $this->confirmedSo([$line]);

        $this->actingAs($this->user)->postJson('/api/v1/sales-orders', [
            'order_date'      => '2026-07-20',
            'customer_id'     => $this->customer->id,
            'sales_person_id' => $this->user->id,
            'status'          => 'confirmed',
            'items'           => [$line],
        ])->assertStatus(422);
    }

    public function test_part_of_a_roll_comes_back_as_a_new_labelled_roll(): void
    {
        $sale = $this->invoicedRollSale([10.0]);
        [$roll] = $sale['rolls'];

        $id = $this->createReturn($this->payload($sale['invoice'], [[
            'pieces' => [['do_piece_id' => $this->doPieceIds($sale['invoice'])[0], 'quantity' => 4]],
        ]]));
        $this->confirm($id)->assertOk();

        $this->assertSame(GrnItemPiece::STATUS_DELIVERED, $roll->fresh()->status);

        $returned = GrnItemPiece::where('parent_piece_id', $roll->id)->sole();
        $this->assertSame($roll->piece_code . '-R1', $returned->piece_code);
        $this->assertSame(GrnItemPiece::STATUS_IN_STOCK, $returned->status);
        $this->assertNull($returned->printed_at);
        $this->assertEqualsWithDelta(4.0, (float) $returned->weight, 0.0001);
        $this->assertEqualsWithDelta(4.0, $this->stockAt($sale['product'], $this->storeId), 0.0001);

        // The rest of the roll can still come back later — and gets the next code.
        $second = $this->createReturn($this->payload($sale['invoice'], [[
            'pieces' => [['do_piece_id' => $this->doPieceIds($sale['invoice'])[0], 'quantity' => 6]],
        ]]));
        $this->confirm($second)->assertOk();

        $this->assertTrue(GrnItemPiece::where('piece_code', $roll->piece_code . '-R2')->exists());
    }

    public function test_a_roll_that_was_cut_on_delivery_returns_as_a_new_roll(): void
    {
        // 6 of a 10 roll sold → the 4 remnant stays in stock as "-C1".
        $sale = $this->invoicedRollSale([10.0], [6.0]);
        [$roll] = $sale['rolls'];

        $this->confirm($this->createReturn($this->payload($sale['invoice'], [[
            'pieces' => [['do_piece_id' => $this->doPieceIds($sale['invoice'])[0], 'quantity' => 6]],
        ]])))->assertOk();

        $this->assertSame(GrnItemPiece::STATUS_DELIVERED, $roll->fresh()->status);
        $this->assertTrue(GrnItemPiece::where('piece_code', $roll->piece_code . '-C1')->where('status', 'in_stock')->exists());
        $this->assertTrue(GrnItemPiece::where('piece_code', $roll->piece_code . '-R1')->where('status', 'in_stock')->exists());
        $this->assertEqualsWithDelta(10.0, $this->stockAt($sale['product'], $this->storeId), 0.0001);
    }

    public function test_a_whole_roll_sold_in_yards_comes_back_whole_from_the_rounded_figure(): void
    {
        // Stocked in metres, sold in yards: a 2 m roll is 2.18722 yd, which the form shows
        // (and the user types) as 2.1872 — that must still be "the whole roll".
        $length = \Modules\Inventory\Models\UnitCategory::create(['name' => 'Length']);
        $metre  = \Modules\Inventory\Models\UnitType::create(['unit_category_id' => $length->id, 'name' => 'Metre', 'symbol' => 'm']);
        $yard   = \Modules\Inventory\Models\UnitType::create(['unit_category_id' => $length->id, 'name' => 'Yard', 'symbol' => 'yd']);
        $length->update(['base_unit_type_id' => $metre->id]);
        \Modules\Inventory\Models\UnitConversion::create(['from_unit_type_id' => $metre->id, 'to_unit_type_id' => $yard->id, 'multiplier' => 1.09361]);
        \Modules\Inventory\Models\UnitConversion::create(['from_unit_type_id' => $yard->id, 'to_unit_type_id' => $metre->id, 'multiplier' => 1 / 1.09361]);

        $product = Product::factory()->create(['base_unit_type_id' => $metre->id]);
        $grn     = GoodsReceivedNote::create(['grn_no' => 'GRN-YD-0001', 'grn_date' => '2026-07-01', 'status' => 'confirmed']);
        $grnItem = GoodsReceivedNoteItem::create(['grn_id' => $grn->id, 'product_id' => $product->id, 'quantity_received' => 2, 'unit_price' => 40]);
        $roll    = GrnItemPiece::create([
            'grn_item_id' => $grnItem->id, 'grn_id' => $grn->id, 'product_id' => $product->id,
            'store_id' => $this->storeId, 'location_id' => $this->locationId,
            'piece_no' => 1, 'weight' => 2, 'piece_code' => 'GRN-YD-0001-I001-P001', 'status' => GrnItemPiece::STATUS_IN_STOCK,
        ]);
        ProductLocationStore::create(['product_id' => $product->id, 'store_id' => $this->storeId, 'location_id' => $this->locationId, 'current_stock' => 2]);

        $so = $this->confirmedSo([[
            'product_id' => $product->id, 'unit_id' => $yard->id, 'quantity' => 2.18722,
            'unit_price' => 40, 'piece_codes' => [$roll->piece_code],
        ]]);
        $invoice = $this->issuedInvoice($this->confirmedDo($so, [[
            'so_item_id' => $so->items()->sole()->id, 'piece_ids' => [$roll->id],
        ]]));

        $this->confirm($this->createReturn($this->payload($invoice, [[
            'pieces' => [['do_piece_id' => $this->doPieceIds($invoice)[0], 'quantity' => 2.1872]],
        ]])))->assertOk();

        $this->assertSame(GrnItemPiece::STATUS_IN_STOCK, $roll->fresh()->status);
        $this->assertFalse(GrnItemPiece::where('parent_piece_id', $roll->id)->exists());
        $this->assertEqualsWithDelta(2.0, $this->stockAt($product, $this->storeId), 0.000001);
    }

    public function test_cannot_return_more_of_a_roll_than_it_delivered(): void
    {
        $sale = $this->invoicedRollSale([10.0, 20.0]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/customer-returns', $this->payload($sale['invoice'], [[
                'pieces' => [['do_piece_id' => $this->doPieceIds($sale['invoice'])[0], 'quantity' => 12]],
            ]]))
            ->assertStatus(422);
    }

    // ── Money ───────────────────────────────────────────────────────────────

    public function test_return_on_an_unpaid_invoice_reduces_its_outstanding(): void
    {
        $sale    = $this->invoicedManualSale(10, 50); // 500
        $invoice = $sale['invoice'];

        $this->confirm($this->createReturn($this->payload($invoice, [['quantity' => 2]])))
            ->assertOk()
            ->assertJsonPath('data.applied_to_invoice', 100)
            ->assertJsonPath('data.credit_note_id', null);

        $this->assertSame(0, CustomerCreditNote::count());

        $openInvoices = app(CustomerReceiptService::class)->getOutstandingInvoicesForCustomer($this->customer->id);
        $this->assertEqualsWithDelta(400.0, $openInvoices[0]['outstanding'], 0.001);

        $report = app(OutstandingSummaryReportService::class)->build(['customer_id' => $this->customer->id]);
        $this->assertEqualsWithDelta(400.0, $report['summary']['total_outstanding'], 0.001);
        $this->assertEqualsWithDelta(100.0, $report['customers'][0]['invoices'][0]['returned'], 0.001);
    }

    public function test_returning_everything_still_owed_marks_the_invoice_paid(): void
    {
        $sale    = $this->invoicedManualSale(10, 50); // 500
        $invoice = $sale['invoice'];
        $this->receive($invoice, 300);

        $this->confirm($this->createReturn($this->payload($invoice, [['quantity' => 4]]))) // 200
            ->assertOk()
            ->assertJsonPath('data.applied_to_invoice', 200);

        $this->assertSame('paid', $invoice->fresh()->status->value);
    }

    public function test_return_on_a_paid_invoice_raises_an_open_credit_note_for_the_excess(): void
    {
        $sale    = $this->invoicedManualSale(10, 50); // 500
        $invoice = $sale['invoice'];
        $this->receive($invoice, 450); // 50 still owed

        $id = $this->createReturn($this->payload($invoice, [['quantity' => 3]])); // 150
        $this->confirm($id)
            ->assertOk()
            ->assertJsonPath('data.applied_to_invoice', 50)
            ->assertJsonPath('data.credit_note.amount', 100);

        $note = CustomerCreditNote::sole();
        $this->assertSame('sales_return', $note->credit_type->value);
        $this->assertSame('open', $note->status->value);
        $this->assertEqualsWithDelta(100.0, (float) $note->remaining_balance, 0.001);
        $this->assertSame($id, $note->source_return_id);
        $this->assertSame('paid', $invoice->fresh()->status->value);

        // The open credit note is offered to the receipt form.
        $this->assertCount(1, app(CustomerReceiptService::class)->getOpenCreditNotesForCustomer($this->customer->id, 'sales_return'));
    }

    public function test_a_return_credit_note_pays_the_customers_next_invoice_by_set_off(): void
    {
        Permission::firstOrCreate(['name' => 'create_customer_receipts', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'confirm_customer_receipts', 'guard_name' => 'web']);
        $this->user->givePermissionTo(['create_customer_receipts', 'confirm_customer_receipts']);

        $paid = $this->invoicedManualSale(10, 50)['invoice']; // 500
        $this->receive($paid, 500);
        $this->confirm($this->createReturn($this->payload($paid, [['quantity' => 2]])))->assertOk(); // 100 credit

        $note = CustomerCreditNote::sole();
        $next = $this->invoicedManualSale(4, 25)['invoice']; // 100

        $receiptId = $this->actingAs($this->user)->postJson('/api/v1/customer-receipts', [
            'receipt_date' => '2026-07-20',
            'customer_id'  => $this->customer->id,
            'is_advance'   => false,
            'allocations'  => [['reference_type' => 'invoice', 'reference_id' => $next->id, 'receipt_amount' => 100]],
            'setoffs'      => [['setoff_type' => 'sales_return', 'credit_note_id' => $note->id, 'amount' => 100]],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->user)->postJson("/api/v1/customer-receipts/{$receiptId}/confirm")->assertOk();

        $this->assertSame('exhausted', $note->fresh()->status->value);
        $this->assertSame('paid', $next->fresh()->status->value);
    }

    public function test_invoice_with_a_confirmed_return_cannot_be_cancelled(): void
    {
        $sale = $this->invoicedManualSale(10);
        $this->confirm($this->createReturn($this->payload($sale['invoice'], [['quantity' => 1]])))->assertOk();

        $this->actingAs($this->user)
            ->patchJson("/api/v1/invoices/{$sale['invoice']->id}/status", ['status' => 'cancelled'])
            ->assertStatus(422);
    }

    public function test_bin_card_names_the_return_document(): void
    {
        $sale = $this->invoicedManualSale(10);
        $this->confirm($this->createReturn($this->payload($sale['invoice'], [['quantity' => 1]])))->assertOk();

        $rows = app(\Modules\Inventory\Services\BinCardService::class)->build(['product_id' => $sale['product']->id])['rows'];
        $this->assertContains('SRN-0001', array_column($rows, 'document_no'));
    }
}
