<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\ProductServiceType;
use Modules\Inventory\Models\Attribute;
use Modules\Inventory\Models\AttributeType;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductAttribute;
use Modules\Inventory\Models\ProductLocationStore;
use Modules\Inventory\Models\SalesChannel;
use Modules\Inventory\Models\SalesOrder;
use Modules\Inventory\Models\StockTransaction;
use Modules\Inventory\Services\ColourStockService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Quantity-tracked products (finished goods, any non-roll item): received on a
 * GRN without rolls, sold by typed quantity, stock checked per colour.
 */
class QuantityTrackedProductTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private CustomerMaster $customer;
    private int $supplierId;
    private int $storeId;
    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view_grns', 'create_grns', 'edit_grns', 'confirm_grns', 'delete_grns',
            'view_sales_orders', 'create_sales_orders', 'edit_sales_orders', 'delete_sales_orders',
            'view_delivery_orders', 'create_delivery_orders', 'edit_delivery_orders', 'delete_delivery_orders',
            'view_products', 'create_products', 'edit_products', 'delete_products',
            'view_invoices', 'create_invoices', 'edit_invoices', 'delete_invoices',
            'view_stock_reconciliations', 'create_stock_reconciliations',
            'edit_stock_reconciliations', 'delete_stock_reconciliations',
            'approve_stock_reconciliations',
        ];
        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo($permissions);

        $this->customer = CustomerMaster::create([
            'customer_code' => 'CUS-0001',
            'customer_name' => 'Garment Buyer',
            'customer_type' => 'Retail',
        ]);
        // Raw insert: the SupplierMaster model lives in a different namespace on
        // the Finance branch, and this test must run on both.
        $this->supplierId = DB::table('inv_supplier_masters')->insertGetId([
            'supplier_code' => 'SUP-0001',
            'supplier_name' => 'Garment Factory',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->locationId = DB::table('inv_locations')->insertGetId([
            'company_id'             => 1,
            'industry_id'            => 1,
            'location_code'          => 'LOC-1',
            'location_name'          => 'Main',
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
        $this->storeId = DB::table('inv_stores')->insertGetId([
            'store_type_id' => 1,
            'location_id'   => $this->locationId,
            'store_code'    => 'STR-1',
            'store_name'    => 'Finished Goods Store',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function colourType(): AttributeType
    {
        $category = Category::firstOrCreate(
            ['category_name' => 'Colour Holder'],
            ['product_service_type' => ProductServiceType::Product],
        );

        return AttributeType::firstOrCreate(
            ['category_id' => $category->id, 'attribute_type_name' => 'Colour'],
            ['product_service_type' => ProductServiceType::Product],
        );
    }

    private function colour(string $name): Attribute
    {
        return Attribute::create(['attribute_type_id' => $this->colourType()->id, 'attribute_name' => $name]);
    }

    /** A quantity-tracked product that offers the given colours. */
    private function finishedGood(Attribute ...$colours): Product
    {
        $product = Product::factory()->quantityTracked()->create();

        foreach ($colours as $colour) {
            ProductAttribute::create([
                'product_id'        => $product->id,
                'attribute_type_id' => $colour->attribute_type_id,
                'attribute_id'      => $colour->id,
            ]);
        }

        return $product;
    }

    /** @param array<array<string, mixed>> $lines */
    private function grnPayload(array $lines, string $shippingCode = 'SHIP-001'): array
    {
        return [
            'supplier_id'   => $this->supplierId,
            'grn_date'      => '2026-10-12',
            'shipping_code' => $shippingCode,
            'store_id'      => $this->storeId,
            'location_id'   => $this->locationId,
            'items'         => $lines,
        ];
    }

    private function grnLine(Product $product, float $qty, ?Attribute $colour = null, float $price = 800.0): array
    {
        return array_filter([
            'product_id'        => $product->id,
            'attribute_id'      => $colour?->id,
            'unit_id'           => $product->base_unit_type_id,
            'quantity_received' => $qty,
            'unit_price'        => $price,
        ], fn ($v) => $v !== null);
    }

    /** Create + confirm a GRN through the API. */
    private function receive(array $lines, string $shippingCode = 'SHIP-001'): GoodsReceivedNote
    {
        $id = $this->actingAs($this->user)
            ->postJson('/api/v1/goods-received-notes', $this->grnPayload($lines, $shippingCode))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->user)
            ->postJson("/api/v1/goods-received-notes/{$id}/confirm")
            ->assertOk();

        return GoodsReceivedNote::findOrFail($id);
    }

    private function soPayload(Product $product, float $qty, ?Attribute $colour, float $price = 1500.0): array
    {
        return [
            'order_date'      => '2026-10-12',
            'customer_id'     => $this->customer->id,
            'sales_person_id' => $this->user->id,
            'status'          => 'confirmed',
            'items'           => [array_filter([
                'product_id'   => $product->id,
                'attribute_id' => $colour?->id,
                'quantity'     => $qty,
                'unit_price'   => $price,
            ], fn ($v) => $v !== null)],
        ];
    }

    private function confirmedSo(Product $product, float $qty, ?Attribute $colour): SalesOrder
    {
        $id = $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->soPayload($product, $qty, $colour))
            ->assertCreated()
            ->json('data.id');

        return SalesOrder::findOrFail($id);
    }

    /** Draft a DO for the whole SO line and try to confirm it. */
    private function deliver(SalesOrder $so, float $qty): \Illuminate\Testing\TestResponse
    {
        $doId = $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', [
                'so_id'         => $so->id,
                'delivery_date' => '2026-10-12',
                'store_id'      => $this->storeId,
                'location_id'   => $this->locationId,
                'items'         => [['so_item_id' => $so->items()->first()->id, 'quantity' => $qty]],
            ])
            ->assertCreated()
            ->json('data.id');

        return $this->actingAs($this->user)
            ->patchJson("/api/v1/delivery-orders/{$doId}/status", ['status' => 'confirmed']);
    }

    /** Confirmed costing for a GRN line (figures per base unit). */
    private function cost(GoodsReceivedNote $grn, Attribute $colour, float $beforeTax, float $afterTax, float $vatPct, bool $applyVat = true): void
    {
        $line = DB::table('inv_goods_received_note_items')
            ->where('grn_id', $grn->id)->where('attribute_id', $colour->id)->first();

        $costingId = DB::table('inv_costings')->insertGetId([
            'document_no'  => 'CST-' . $line->id,
            'reference_no' => 'CRef-' . $line->id,
            'supplier_id'  => $this->supplierId,
            'costing_type' => 'fob',
            'status'       => 'confirmed',
            'apply_vat'    => $applyVat ? 1 : 0,
            'vat_pct'      => $vatPct,
            'confirmed_at' => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('inv_costing_items')->insert([
            'costing_id'            => $costingId,
            'grn_id'                => $grn->id,
            'grn_item_id'           => $line->id,
            'product_id'            => $line->product_id,
            'attribute_id'          => $colour->id,
            'quantity'              => $line->quantity_received,
            'conversion_factor'     => 1,
            'base_quantity'         => $line->quantity_received,
            'unit_price'            => $line->unit_price,
            'before_tax_price'      => $beforeTax,
            'before_tax_price_base' => $beforeTax,
            'selling_price'         => $afterTax,
            'selling_price_base'    => $afterTax,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
    }

    /** GRN → costing → SO (at $soPrice) → DO → tax invoice; returns the invoice JSON line. */
    private function taxInvoiceLine(float $soPrice, float $beforeTax, float $afterTax, float $vatPct, bool $applyVat = true): array
    {
        $red     = $this->colour('Red');
        $product = $this->finishedGood($red);
        $grn     = $this->receive([$this->grnLine($product, 10, $red)]);
        $this->cost($grn, $red, $beforeTax, $afterTax, $vatPct, $applyVat);

        $soId = $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->soPayload($product, 4, $red, $soPrice))
            ->assertCreated()->json('data.id');
        $so = SalesOrder::findOrFail($soId);
        $this->deliver($so, 4)->assertOk();

        $doId = DB::table('inv_delivery_orders')->where('so_id', $so->id)->value('id');

        return $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-10-12', 'invoice_type' => 'tax'])
            ->assertCreated()
            ->json('data.items.0');
    }

    // ── Product setting ───────────────────────────────────────────────────────

    public function test_existing_style_products_default_to_roll_tracking(): void
    {
        $product = Product::factory()->create();

        $this->assertTrue($product->fresh()->isRollTracked());
        $this->assertFalse($this->finishedGood()->isRollTracked());
    }

    public function test_stock_tracking_is_locked_once_stock_has_moved(): void
    {
        $red     = $this->colour('Red');
        $product = $this->finishedGood($red);
        $channel = SalesChannel::create(['sales_channel_name' => 'Retail', 'type' => 'Retail']);

        $payload = fn (string $tracking) => [
            'name'              => $product->name,
            'display_name'      => 'T-Shirt',
            'product_type'      => 'Finished Good',
            'stock_tracking'    => $tracking,
            'supplier_ids'      => [$this->supplierId],
            'category_id'       => $product->category_id,
            'base_unit_type_id' => $product->base_unit_type_id,
            'cost_details'      => [[
                'sales_channel_id' => $channel->id,
                'num_of_units'     => 1,
                'cost_price'       => 800,
                'selling_price'    => 1500,
            ]],
        ];

        // No stock yet → switching is allowed.
        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$product->id}", $payload('roll'))
            ->assertOk()
            ->assertJsonPath('data.stock_tracking', 'roll');

        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$product->id}", $payload('quantity'))
            ->assertOk();

        $this->receive([$this->grnLine($product, 10, $red)]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$product->id}", $payload('roll'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('stock_tracking');
    }

    // ── GRN ───────────────────────────────────────────────────────────────────

    public function test_quantity_product_grn_posts_stock_without_rolls(): void
    {
        $red     = $this->colour('Red');
        $blue    = $this->colour('Blue');
        $product = $this->finishedGood($red, $blue);

        $grn = $this->receive([
            $this->grnLine($product, 30, $red),
            $this->grnLine($product, 20, $blue),
        ]);

        $this->assertSame('confirmed', $grn->status->value);
        $this->assertSame(0, GrnItemPiece::where('grn_id', $grn->id)->count());

        $pivot = ProductLocationStore::where('product_id', $product->id)
            ->where('store_id', $this->storeId)->where('location_id', $this->locationId)->first();
        $this->assertEquals(50.0, (float) $pivot->current_stock);

        $colourStock = app(ColourStockService::class);
        $this->assertEquals(30.0, $colourStock->available($product->id, $red->id, $this->storeId, $this->locationId));
        $this->assertEquals(20.0, $colourStock->available($product->id, $blue->id, $this->storeId, $this->locationId));
    }

    public function test_roll_product_grn_still_requires_rolls(): void
    {
        $product = Product::factory()->create(); // roll-tracked (default)

        $this->actingAs($this->user)
            ->postJson('/api/v1/goods-received-notes', $this->grnPayload([$this->grnLine($product, 10)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.rolls');
    }

    public function test_quantity_product_grn_rejects_rolls(): void
    {
        $product = $this->finishedGood();
        $line    = $this->grnLine($product, 10) + ['rolls' => [['roll_no' => 'R1', 'weight' => 10]]];

        $this->actingAs($this->user)
            ->postJson('/api/v1/goods-received-notes', $this->grnPayload([$line]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.rolls');
    }

    public function test_quantity_product_grn_requires_colour_when_product_has_colours(): void
    {
        $product = $this->finishedGood($this->colour('Red'));

        $this->actingAs($this->user)
            ->postJson('/api/v1/goods-received-notes', $this->grnPayload([$this->grnLine($product, 10)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.attribute_id');
    }

    // ── Sales Order ───────────────────────────────────────────────────────────

    public function test_so_requires_colour_for_quantity_product_with_colours(): void
    {
        $red     = $this->colour('Red');
        $product = $this->finishedGood($red);
        $this->receive([$this->grnLine($product, 10, $red)]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->soPayload($product, 5, null))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.attribute_id');
    }

    public function test_so_rejects_colour_with_no_stock(): void
    {
        $red     = $this->colour('Red');
        $blue    = $this->colour('Blue');
        $product = $this->finishedGood($red, $blue);
        $this->receive([$this->grnLine($product, 10, $blue)]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->soPayload($product, 5, $red))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.attribute_id');
    }

    // ── Delivery Order: strict per-colour stock ───────────────────────────────

    public function test_do_confirm_blocks_colour_shortfall_even_when_product_total_suffices(): void
    {
        $red     = $this->colour('Red');
        $blue    = $this->colour('Blue');
        $product = $this->finishedGood($red, $blue);
        $this->receive([
            $this->grnLine($product, 20, $red),
            $this->grnLine($product, 30, $blue),
        ]);

        // Product total 50 ≥ 25, but only 20 Red.
        $so = $this->confirmedSo($product, 25, $red);

        $this->deliver($so, 25)
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Insufficient stock for ' . $product->name . ' (Red): available 20.0000 tu, required 25.0000 tu.']);

        $this->assertSame(0, StockTransaction::where('reference_type', 'sales_delivery')->count());
    }

    public function test_do_confirm_ships_colour_and_decrements_its_balance(): void
    {
        $red     = $this->colour('Red');
        $blue    = $this->colour('Blue');
        $product = $this->finishedGood($red, $blue);
        $this->receive([
            $this->grnLine($product, 30, $red),
            $this->grnLine($product, 20, $blue),
        ]);

        $so = $this->confirmedSo($product, 10, $red);
        $this->deliver($so, 10)->assertOk();

        $colourStock = app(ColourStockService::class);
        $this->assertEquals(20.0, $colourStock->available($product->id, $red->id, $this->storeId, $this->locationId));
        $this->assertEquals(20.0, $colourStock->available($product->id, $blue->id, $this->storeId, $this->locationId));

        $pivot = ProductLocationStore::where('product_id', $product->id)
            ->where('store_id', $this->storeId)->where('location_id', $this->locationId)->first();
        $this->assertEquals(40.0, (float) $pivot->current_stock);

        $out = StockTransaction::where('reference_type', 'sales_delivery')->sole();
        $this->assertSame($red->id, (int) $out->attribute_id);
        $this->assertEquals(10.0, (float) $out->qty_out);
    }

    public function test_allow_minus_bypasses_colour_check(): void
    {
        $red     = $this->colour('Red');
        $blue    = $this->colour('Blue');
        $product = $this->finishedGood($red, $blue);
        $product->update(['allow_minus' => true]);
        $this->receive([$this->grnLine($product, 30, $blue)]);

        $so = $this->confirmedSo($product, 5, $red);
        $this->deliver($so, 5)->assertOk();

        $this->assertEquals(-5.0, app(ColourStockService::class)->available($product->id, $red->id));
    }

    public function test_by_colour_endpoint_lists_balances(): void
    {
        $red     = $this->colour('Red');
        $blue    = $this->colour('Blue');
        $product = $this->finishedGood($red, $blue);
        $this->receive([
            $this->grnLine($product, 30, $red),
            $this->grnLine($product, 20, $blue),
        ]);

        $rows = $this->actingAs($this->user)
            ->getJson("/api/v1/stock/by-colour?product_id={$product->id}")
            ->assertOk()
            ->json('data');

        $byColour = collect($rows)->pluck('quantity', 'color')->all();
        $this->assertEquals(['Red' => 30.0, 'Blue' => 20.0], $byColour);
    }

    // ── Invoice: tax pricing for quantity lines ───────────────────────────────

    public function test_tax_invoice_uses_costing_price_when_so_price_matches(): void
    {
        $line = $this->taxInvoiceLine(soPrice: 1500, beforeTax: 1339.29, afterTax: 1500, vatPct: 12);

        $this->assertEquals(1339.29, (float) $line['unit_price']);
        $this->assertEquals(12.0, (float) $line['tax']);
    }

    public function test_tax_invoice_keeps_typed_so_price_at_costing_vat_rate(): void
    {
        // Costing sells at 1400 incl. 12% VAT, but the SO quoted 1500.
        $line = $this->taxInvoiceLine(soPrice: 1500, beforeTax: 1250, afterTax: 1400, vatPct: 12);

        $this->assertEquals(1339.29, (float) $line['unit_price']); // 1500 / 1.12
        $this->assertEquals(12.0, (float) $line['tax']);
    }

    public function test_tax_invoice_respects_costing_without_vat(): void
    {
        $line = $this->taxInvoiceLine(soPrice: 1500, beforeTax: 1500, afterTax: 1500, vatPct: 18, applyVat: false);

        $this->assertEquals(1500.0, (float) $line['unit_price']);
        $this->assertEquals(0.0, (float) $line['tax']);
    }

    // ── Stock reconciliation: counted per colour ──────────────────────────────

    public function test_counted_qty_reconciliation_corrects_only_the_counted_colour(): void
    {
        $red     = $this->colour('Red');
        $blue    = $this->colour('Blue');
        $product = $this->finishedGood($red, $blue);
        $this->receive([
            $this->grnLine($product, 30, $red),
            $this->grnLine($product, 20, $blue),
        ]);

        $id = $this->actingAs($this->user)->postJson('/api/v1/stock-reconciliations', [
            'product_id'   => $product->id,
            'attribute_id' => $red->id,
            'store_id'     => $this->storeId,
            'location_id'  => $this->locationId,
            'reason'       => 'Physical count: 28 Red',
            'counted_qty'  => 28,
        ])->assertCreated()
            ->assertJsonPath('data.system_qty_base', 30)
            ->json('data.id');

        $this->actingAs($this->user)->postJson("/api/v1/stock-reconciliations/{$id}/submit")->assertOk();
        $this->actingAs($this->user)->postJson("/api/v1/stock-reconciliations/{$id}/approve")->assertOk();

        $colourStock = app(ColourStockService::class);
        $this->assertEquals(28.0, $colourStock->available($product->id, $red->id, $this->storeId, $this->locationId));
        $this->assertEquals(20.0, $colourStock->available($product->id, $blue->id, $this->storeId, $this->locationId));

        $pivot = ProductLocationStore::where('product_id', $product->id)
            ->where('store_id', $this->storeId)->where('location_id', $this->locationId)->first();
        $this->assertEquals(48.0, (float) $pivot->current_stock);
    }

    public function test_counted_qty_reconciliation_requires_colour_for_colour_products(): void
    {
        $red     = $this->colour('Red');
        $product = $this->finishedGood($red);

        $this->actingAs($this->user)->postJson('/api/v1/stock-reconciliations', [
            'product_id'  => $product->id,
            'store_id'    => $this->storeId,
            'location_id' => $this->locationId,
            'reason'      => 'Count',
            'counted_qty' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors('attribute_id');
    }

    // ── Delivery Order source: what is still free to ship ─────────────────────

    public function test_do_source_reports_quantity_still_free_after_other_drafts(): void
    {
        $red     = $this->colour('Red');
        $product = $this->finishedGood($red);
        $this->receive([$this->grnLine($product, 30, $red)]);
        $so = $this->confirmedSo($product, 10, $red);

        // Another draft DO already claims 4 of the 10.
        $this->actingAs($this->user)->postJson('/api/v1/delivery-orders', [
            'so_id'         => $so->id,
            'delivery_date' => '2026-10-13',
            'store_id'      => $this->storeId,
            'location_id'   => $this->locationId,
            'items'         => [['so_item_id' => $so->items()->first()->id, 'quantity' => 4]],
        ])->assertCreated();

        $line = $this->actingAs($this->user)
            ->getJson("/api/v1/delivery-orders/from-so/{$so->id}")
            ->assertOk()
            ->json('data.items.0');

        $this->assertEquals(10.0, (float) $line['remaining']);
        $this->assertEquals(6.0, (float) $line['available_to_ship']);
    }
}
