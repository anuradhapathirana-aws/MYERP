<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GoodsReceivedNoteItem;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\Product;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Proforma-first flow: a proforma is raised before any SO (any product, in stock
 * or not), then recalled into one or more SOs whose DO invoices bill the quoted
 * prices and drive Partially Invoiced / Converted.
 */
class ProformaInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private CustomerMaster $customer;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view_sales_orders', 'create_sales_orders', 'edit_sales_orders', 'delete_sales_orders',
            'view_delivery_orders', 'create_delivery_orders', 'edit_delivery_orders', 'delete_delivery_orders',
            'view_invoices', 'create_invoices', 'edit_invoices', 'delete_invoices',
            'view_proforma_invoices', 'create_proforma_invoices', 'edit_proforma_invoices', 'delete_proforma_invoices',
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
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Receive rolls of a product (confirmed GRN) — returns the pieces. */
    private function receiveRolls(Product $product, array $weights): array
    {
        static $seq = 0;
        $seq++;

        $grn = GoodsReceivedNote::create([
            'grn_no'   => sprintf('GRN-PI-%04d', $seq),
            'grn_date' => '2026-07-01',
            'status'   => 'confirmed',
        ]);
        $grnItem = GoodsReceivedNoteItem::create([
            'grn_id'            => $grn->id,
            'product_id'        => $product->id,
            'quantity_received' => array_sum($weights),
            'unit_price'        => 400,
        ]);

        $pieces = [];
        foreach ($weights as $i => $weight) {
            $pieces[] = GrnItemPiece::create([
                'grn_item_id' => $grnItem->id,
                'grn_id'      => $grn->id,
                'product_id'  => $product->id,
                'piece_no'    => $i + 1,
                'weight'      => $weight,
                'piece_code'  => sprintf('%s-I001-P%03d', $grn->grn_no, $i + 1),
                'status'      => GrnItemPiece::STATUS_IN_STOCK,
            ]);
        }

        $pivot = \Modules\Inventory\Models\ProductLocationStore::firstOrCreate(
            ['product_id' => $product->id, 'store_id' => null, 'location_id' => null],
            ['current_stock' => 0],
        );
        $pivot->increment('current_stock', array_sum($weights));

        return $pieces;
    }

    private function line(Product $product, float $qty, float $price, array $extra = []): array
    {
        return array_merge([
            'product_id' => $product->id,
            'unit_id'    => $product->base_unit_type_id,
            'quantity'   => $qty,
            'unit_price' => $price,
        ], $extra);
    }

    private function createProforma(array $items, array $extra = []): TestResponse
    {
        return $this->actingAs($this->user)->postJson('/api/v1/proforma-invoices', array_merge([
            'customer_id'   => $this->customer->id,
            'proforma_date' => '2026-07-09',
            'invoice_type'  => 'non_tax',
            'items'         => $items,
        ], $extra));
    }

    private function issue(int $proformaId): TestResponse
    {
        return $this->actingAs($this->user)
            ->patchJson("/api/v1/proforma-invoices/{$proformaId}/status", ['status' => 'issued'])
            ->assertOk()
            ->assertJsonPath('data.status', 'issued');
    }

    private function show(int $proformaId): TestResponse
    {
        return $this->actingAs($this->user)->getJson("/api/v1/proforma-invoices/{$proformaId}")->assertOk();
    }

    /** "Create SO from Proforma": a confirmed SO whose roll line links to the proforma line. */
    private function recallIntoSo(int $proformaItemId, Product $product, array $pieces, float $unitPrice, array $extra = []): TestResponse
    {
        return $this->actingAs($this->user)->postJson('/api/v1/sales-orders', array_merge([
            'order_date'      => '2026-07-10',
            'customer_id'     => $this->customer->id,
            'sales_person_id' => $this->user->id,
            'status'          => 'confirmed',
            'items'           => [[
                'proforma_item_id' => $proformaItemId,
                'product_id'       => $product->id,
                'unit_id'          => $product->base_unit_type_id,
                'unit_price'       => $unitPrice,
                'piece_codes'      => array_map(fn (GrnItemPiece $p) => $p->piece_code, $pieces),
            ]],
        ], $extra));
    }

    private function confirmedDo(int $soId, array $pieces): int
    {
        $soItemId = DB::table('inv_sales_order_items')->where('so_id', $soId)->value('id');

        $doId = $this->actingAs($this->user)->postJson('/api/v1/delivery-orders', [
            'so_id'         => $soId,
            'delivery_date' => '2026-07-11',
            'items'         => [['so_item_id' => $soItemId, 'piece_ids' => array_map(fn (GrnItemPiece $p) => $p->id, $pieces)]],
        ])->json('data.id');

        $this->actingAs($this->user)
            ->patchJson("/api/v1/delivery-orders/{$doId}/status", ['status' => 'confirmed'])
            ->assertOk();

        return $doId;
    }

    private function invoiceDo(int $doId): TestResponse
    {
        return $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-07-12'])
            ->assertCreated();
    }

    // ── Auth ────────────────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/proforma-invoices')->assertUnauthorized();
    }

    public function test_user_without_view_permission_is_forbidden(): void
    {
        $restricted = User::factory()->create(['active_modules' => ['inventory']]);

        $this->actingAs($restricted)->getJson('/api/v1/proforma-invoices')->assertForbidden();
    }

    // ── Create / edit ───────────────────────────────────────────────────────

    public function test_non_stock_product_can_be_quoted_without_a_sales_order(): void
    {
        $newProduct = Product::factory()->create(); // never received — no stock
        $stockRows  = DB::table('inv_stock_transactions')->count();

        $this->createProforma([$this->line($newProduct, 50, 1000, ['discount' => 10])], ['transport_charge' => 500])
            ->assertCreated()
            ->assertJsonPath('data.proforma_no', 'PI-26JUL_CUS-0001_0001')
            ->assertJsonPath('data.source_type', 'direct')
            ->assertJsonPath('data.so_id', null)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.valid_until', '2026-07-23')
            ->assertJsonPath('data.items.0.quantity', 50)
            ->assertJsonPath('data.items.0.unit_price', 1000)
            ->assertJsonPath('data.items.0.tax', 0)
            // 50 × 1000 = 50000, −10% = 45000, + transport 500
            ->assertJsonPath('data.subtotal', 50000)
            ->assertJsonPath('data.grand_total', 45500);

        $this->assertSame($stockRows, DB::table('inv_stock_transactions')->count());
        $this->assertSame(0, DB::table('inv_sales_orders')->count());
    }

    public function test_issue_snapshots_stock_per_line_for_mixed_quotes(): void
    {
        $stocked = Product::factory()->create();
        $this->receiveRolls($stocked, [10.0, 20.0]); // 30 available
        $partial = Product::factory()->create();
        $this->receiveRolls($partial, [5.0]);        // 5 available
        $none    = Product::factory()->create();

        $id = $this->createProforma([
            $this->line($stocked, 25, 100),
            $this->line($partial, 12, 100),
            $this->line($none, 8, 100),
        ])->json('data.id');

        $this->issue($id)
            ->assertJsonPath('data.items.0.stock_status', 'in_stock')
            ->assertJsonPath('data.items.0.available_qty', 30)
            ->assertJsonPath('data.items.1.stock_status', 'partial')
            ->assertJsonPath('data.items.1.available_qty', 5)
            ->assertJsonPath('data.items.2.stock_status', 'non_stock')
            ->assertJsonPath('data.items.2.available_qty', 0);

        // A proforma never reserves stock
        $this->assertSame(3, GrnItemPiece::where('status', GrnItemPiece::STATUS_IN_STOCK)->count());
    }

    public function test_live_stock_check_endpoint(): void
    {
        $stocked = Product::factory()->create();
        $this->receiveRolls($stocked, [10.0]);
        $none = Product::factory()->create();

        $this->actingAs($this->user)
            ->postJson('/api/v1/proforma-invoices/stock-check', ['lines' => [
                ['product_id' => $stocked->id, 'quantity' => 4],
                ['product_id' => $stocked->id, 'quantity' => 40],
                ['product_id' => $none->id, 'quantity' => 1],
            ]])
            ->assertOk()
            ->assertJsonPath('data.0.stock_status', 'in_stock')
            ->assertJsonPath('data.1.stock_status', 'partial')
            ->assertJsonPath('data.2.stock_status', 'non_stock');
    }

    public function test_tax_proforma_adds_vat_and_non_tax_forces_zero(): void
    {
        $p = Product::factory()->create();

        $this->createProforma([$this->line($p, 10, 1000, ['tax' => 18])], ['invoice_type' => 'tax'])
            ->assertJsonPath('data.items.0.tax', 18)
            ->assertJsonPath('data.grand_total', 11800);

        $this->createProforma([$this->line($p, 10, 1000, ['tax' => 18])], ['invoice_type' => 'non_tax'])
            ->assertJsonPath('data.items.0.tax', 0)
            ->assertJsonPath('data.grand_total', 10000);
    }

    public function test_draft_edit_replaces_lines_and_issued_is_locked(): void
    {
        $a = Product::factory()->create();
        $b = Product::factory()->create();
        $id = $this->createProforma([$this->line($a, 10, 100)])->json('data.id');

        $this->actingAs($this->user)
            ->putJson("/api/v1/proforma-invoices/{$id}", [
                'proforma_date' => '2026-07-09',
                'payment_terms' => '50% advance',
                'items'         => [$this->line($a, 5, 100), $this->line($b, 2, 300)],
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_terms', '50% advance')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.grand_total', 1100);

        $this->issue($id);

        $this->actingAs($this->user)
            ->putJson("/api/v1/proforma-invoices/{$id}", ['proforma_date' => '2026-07-09', 'items' => [$this->line($a, 1, 1)]])
            ->assertStatus(422);
        $this->actingAs($this->user)->deleteJson("/api/v1/proforma-invoices/{$id}")->assertStatus(422);
    }

    public function test_draft_can_be_deleted_and_number_is_never_taken_from_input(): void
    {
        $p  = Product::factory()->create();
        $id = $this->createProforma([$this->line($p, 1, 1)], ['proforma_no' => 'HACKED'])
            ->assertJsonPath('data.proforma_no', 'PI-26JUL_CUS-0001_0001')
            ->json('data.id');

        $this->actingAs($this->user)->deleteJson("/api/v1/proforma-invoices/{$id}")->assertNoContent();
        $this->assertSoftDeleted('inv_proforma_invoices', ['id' => $id]);
    }

    public function test_system_statuses_cannot_be_set_by_user(): void
    {
        $id = $this->createProforma([$this->line(Product::factory()->create(), 1, 1)])->json('data.id');

        $this->actingAs($this->user)
            ->patchJson("/api/v1/proforma-invoices/{$id}/status", ['status' => 'converted'])
            ->assertStatus(422);
    }

    // ── Recall into sales orders ────────────────────────────────────────────

    public function test_recall_source_requires_an_issued_proforma_and_prices_like_an_so(): void
    {
        $p = Product::factory()->create();
        $this->receiveRolls($p, [10.0]);
        $id = $this->createProforma([$this->line($p, 10, 1000, ['tax' => 18, 'discount' => 5])], [
            'invoice_type'     => 'tax',
            'transport_charge' => 300,
        ])->json('data.id');

        $this->actingAs($this->user)->getJson("/api/v1/proforma-invoices/{$id}/recall")->assertStatus(422);

        $this->issue($id);

        $this->actingAs($this->user)->getJson("/api/v1/proforma-invoices/{$id}/recall")
            ->assertOk()
            ->assertJsonPath('data.proforma.customer_id', $this->customer->id)
            ->assertJsonPath('data.proforma.transport_charge', 300)
            ->assertJsonPath('data.lines.0.remaining_quantity', 10)
            // SO prices carry VAT inside: 1000 + 18% = 1180
            ->assertJsonPath('data.lines.0.so_unit_price', 1180)
            ->assertJsonPath('data.lines.0.discount', 5)
            ->assertJsonPath('data.lines.0.stock_status', 'in_stock');
    }

    public function test_one_proforma_recalled_into_two_sales_orders_and_invoiced_at_quoted_price(): void
    {
        $inStock  = Product::factory()->create();
        $piecesA  = $this->receiveRolls($inStock, [10.0, 20.0]);
        $onOrder  = Product::factory()->create(); // arrives later

        $pf = $this->createProforma([
            $this->line($inStock, 30, 1000, ['tax' => 18]),
            $this->line($onOrder, 15, 2000, ['tax' => 18]),
        ], ['invoice_type' => 'tax', 'transport_charge' => 500]);
        $id      = $pf->json('data.id');
        $itemA   = $pf->json('data.items.0.id');
        $itemB   = $pf->json('data.items.1.id');
        $this->issue($id);

        // SO #1 — the in-stock line now
        $so1 = $this->recallIntoSo($itemA, $inStock, $piecesA, 1180)->assertCreated()->json('data.id');
        $this->show($id)
            ->assertJsonPath('data.items.0.recalled_quantity', 30)
            ->assertJsonPath('data.items.1.recalled_quantity', 0)
            ->assertJsonCount(1, 'data.sales_orders');

        // The recall pre-fill now offers only the line still to recall, without transport again
        $this->actingAs($this->user)->getJson("/api/v1/proforma-invoices/{$id}/recall")
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.proforma_item_id', $itemB)
            ->assertJsonPath('data.lines.0.stock_status', 'non_stock')
            ->assertJsonPath('data.proforma.transport_charge', 0);

        // The costing is revised after the quote went out — the invoice still bills the quote
        $inv1 = $this->invoiceDo($this->confirmedDo($so1, $piecesA))
            ->assertJsonPath('data.proforma_id', $id)
            ->assertJsonPath('data.invoice_type', 'tax')
            ->assertJsonPath('data.items.0.unit_price', 1000)
            ->assertJsonPath('data.items.0.tax', 18)
            ->assertJsonPath('data.items.0.proforma_item_id', $itemA);
        $this->assertSame('partially_invoiced', $this->show($id)->json('data.status'));

        // The on-order goods arrive → SO #2 for the rest
        $piecesB = $this->receiveRolls($onOrder, [15.0]);
        $so2     = $this->recallIntoSo($itemB, $onOrder, $piecesB, 2360)->assertCreated()->json('data.id');
        $inv2    = $this->invoiceDo($this->confirmedDo($so2, $piecesB))
            ->assertJsonPath('data.items.0.unit_price', 2000)
            ->json('data.id');

        $this->show($id)
            ->assertJsonPath('data.status', 'converted')
            ->assertJsonCount(2, 'data.sales_orders')
            ->assertJsonCount(2, 'data.invoices');

        // Cancelling an invoice rolls the proforma back
        $this->actingAs($this->user)->patchJson("/api/v1/invoices/{$inv2}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertSame('partially_invoiced', $this->show($id)->json('data.status'));
        $this->assertNotNull($inv1->json('data.id'));

        // The SO shows where its lines came from
        $this->actingAs($this->user)->getJson("/api/v1/sales-orders/{$so1}")
            ->assertJsonPath('data.proformas.0.id', $id)
            ->assertJsonPath('data.items.0.proforma_item_id', $itemA);
    }

    public function test_recall_is_rejected_for_another_customer_a_draft_or_a_different_product(): void
    {
        $p      = Product::factory()->create();
        $pieces = $this->receiveRolls($p, [10.0]);
        $other  = Product::factory()->create();
        $this->receiveRolls($other, [10.0]);

        $draft  = $this->createProforma([$this->line($p, 10, 100)]);
        $this->recallIntoSo($draft->json('data.items.0.id'), $p, $pieces, 100)->assertStatus(422);

        $pf = $this->createProforma([$this->line($p, 10, 100)]);
        $this->issue($pf->json('data.id'));

        $otherCustomer = CustomerMaster::create(['customer_code' => 'CUS-0002', 'customer_name' => 'Other', 'customer_type' => 'Retail']);
        $this->recallIntoSo($pf->json('data.items.0.id'), $p, $pieces, 100, ['customer_id' => $otherCustomer->id])
            ->assertStatus(422);

        $otherPieces = GrnItemPiece::where('product_id', $other->id)->get()->all();
        $this->recallIntoSo($pf->json('data.items.0.id'), $other, $otherPieces, 100)->assertStatus(422);
    }

    public function test_recalled_non_stock_line_still_obeys_the_so_stock_rule(): void
    {
        $p  = Product::factory()->quantityTracked()->create(); // no stock at all
        $pf = $this->createProforma([$this->line($p, 5, 100)]);
        $this->issue($pf->json('data.id'));

        $this->actingAs($this->user)->postJson('/api/v1/sales-orders', [
            'order_date'      => '2026-07-10',
            'customer_id'     => $this->customer->id,
            'sales_person_id' => $this->user->id,
            'status'          => 'confirmed',
            'items'           => [[
                'proforma_item_id' => $pf->json('data.items.0.id'),
                'product_id'       => $p->id,
                'unit_id'          => $p->base_unit_type_id,
                'quantity'         => 5,
                'unit_price'       => 100,
            ]],
        ])->assertStatus(422);
    }

    public function test_recalled_proforma_cannot_be_cancelled(): void
    {
        $p      = Product::factory()->create();
        $pieces = $this->receiveRolls($p, [10.0]);
        $pf     = $this->createProforma([$this->line($p, 10, 100)]);
        $this->issue($pf->json('data.id'));
        $this->recallIntoSo($pf->json('data.items.0.id'), $p, $pieces, 100)->assertCreated();

        $this->actingAs($this->user)
            ->patchJson("/api/v1/proforma-invoices/{$pf->json('data.id')}/status", ['status' => 'cancelled'])
            ->assertStatus(422);
    }

    public function test_so_without_a_proforma_invoices_as_before(): void
    {
        $p      = Product::factory()->create();
        $pieces = $this->receiveRolls($p, [10.0]);

        $soId = $this->actingAs($this->user)->postJson('/api/v1/sales-orders', [
            'order_date'      => '2026-07-10',
            'customer_id'     => $this->customer->id,
            'sales_person_id' => $this->user->id,
            'status'          => 'confirmed',
            'items'           => [['product_id' => $p->id, 'unit_price' => 1000, 'piece_codes' => [$pieces[0]->piece_code]]],
        ])->assertCreated()->json('data.id');

        $this->invoiceDo($this->confirmedDo($soId, $pieces))->assertJsonPath('data.proforma_id', null);
    }

    public function test_default_type_follows_vat_registration_not_just_any_tin(): void
    {
        $p = Product::factory()->create();

        // Plain TIN (no VAT code 7000) → Non Tax
        $this->customer->update(['customer_tin' => '114567890']);
        $this->createProforma([$this->line($p, 1, 100)], ['invoice_type' => null])
            ->assertCreated()
            ->assertJsonPath('data.invoice_type', 'non_tax');

        // VAT registration number (TIN + 7000) → Tax
        $this->customer->update(['customer_tin' => '114567890-7000']);
        $this->createProforma([$this->line($p, 1, 100)], ['invoice_type' => null])
            ->assertCreated()
            ->assertJsonPath('data.invoice_type', 'tax');

        // The customer list feeds the form's auto-select
        Permission::firstOrCreate(['name' => 'view_customer_masters', 'guard_name' => 'web']);
        $this->user->givePermissionTo('view_customer_masters');
        $this->actingAs($this->user)->getJson('/api/v1/customer-masters/all')
            ->assertOk()
            ->assertJsonFragment(['id' => $this->customer->id, 'vat_registered' => true]);
    }

    // ── Tax / Non-Tax price sets (same pair the invoice toggle switches) ────

    public function test_price_preview_from_price_list_strips_default_vat(): void
    {
        $p         = Product::factory()->create();
        $channelId = DB::table('inv_sales_channels')->insertGetId(['sales_channel_name' => 'Retail', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inv_product_sales_channels')->insert([
            'product_id' => $p->id, 'sales_channel_id' => $channelId, 'selling_price' => 1180,
            'unit_type_id' => $p->base_unit_type_id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Non-Tax keeps VAT inside (1180); Tax = 1180 ÷ 1.18 = 1000 + 18% — same customer total
        $this->actingAs($this->user)
            ->postJson('/api/v1/proforma-invoices/price-preview', ['lines' => [['product_id' => $p->id, 'unit_id' => $p->base_unit_type_id]]])
            ->assertOk()
            ->assertJsonPath('data.0.source', 'price_list')
            ->assertJsonPath('data.0.unit_price', 1180)
            ->assertJsonPath('data.0.tax_unit_price', 1000)
            ->assertJsonPath('data.0.tax_vat_pct', 18);
    }

    public function test_price_preview_prefers_the_confirmed_costing(): void
    {
        $p      = Product::factory()->create();
        $pieces = $this->receiveRolls($p, [10.0]);

        $costingId = DB::table('inv_costings')->insertGetId([
            'document_no' => 'CST-PI-1', 'reference_no' => 'CRef-PI-1', 'supplier_id' => 1, 'costing_type' => 'fob',
            'status' => 'confirmed', 'apply_vat' => 1, 'vat_pct' => 15, 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inv_costing_items')->insert([
            'costing_id' => $costingId, 'grn_id' => $pieces[0]->grn_id, 'grn_item_id' => $pieces[0]->grn_item_id, 'product_id' => $p->id,
            'quantity' => 10, 'conversion_factor' => 1, 'base_quantity' => 10, 'unit_price' => 400,
            'before_tax_price' => 1000, 'before_tax_price_base' => 1000, 'selling_price' => 1150, 'selling_price_base' => 1150,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/proforma-invoices/price-preview', ['lines' => [['product_id' => $p->id]]])
            ->assertOk()
            ->assertJsonPath('data.0.source', 'costing')
            ->assertJsonPath('data.0.unit_price', 1150)
            ->assertJsonPath('data.0.tax_unit_price', 1000)
            ->assertJsonPath('data.0.tax_vat_pct', 15);
    }

    // ── Preview & PDF ───────────────────────────────────────────────────────

    public function test_next_no_preview_uses_the_customer(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/v1/proforma-invoices/next-no?customer_id={$this->customer->id}&proforma_date=2026-08-01")
            ->assertOk()
            ->assertJsonPath('data', 'PI-26AUG_CUS-0001_0001');
    }

    public function test_pdf_downloads(): void
    {
        $id = $this->createProforma([$this->line(Product::factory()->create(), 3, 100)], ['payment_terms' => '100% advance'])
            ->json('data.id');
        $this->issue($id);

        $this->actingAs($this->user)
            ->get("/api/v1/proforma-invoices/{$id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
