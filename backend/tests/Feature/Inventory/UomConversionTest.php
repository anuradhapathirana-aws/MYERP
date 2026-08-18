<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GoodsReceivedNoteItem;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductLocationStore;
use Modules\Inventory\Models\SalesOrder;
use Modules\Inventory\Models\StockTransaction;
use Modules\Inventory\Models\SupplierMaster;
use Modules\Inventory\Models\UnitCategory;
use Modules\Inventory\Models\UnitConversion;
use Modules\Inventory\Models\UnitType;
use Modules\Inventory\Services\RollService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A product may be purchased in one UOM and sold in another (buy in Kg, sell in g).
 * Stock is held in the product's base (stocking) UOM, and every document line is
 * converted into it via inv_unit_conversions before it touches a balance —
 * otherwise 100 Kg in and 500 g out would subtract 500 from 100.
 */
class UomConversionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private CustomerMaster $customer;
    private UnitType $kg;
    private UnitType $gram;
    private UnitType $litre;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view_grns', 'create_grns', 'confirm_grns',
            'view_sales_orders', 'create_sales_orders',
            'view_delivery_orders', 'create_delivery_orders', 'edit_delivery_orders',
        ];
        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo($permissions);

        $this->customer = CustomerMaster::create([
            'customer_code' => 'CUS-0001',
            'customer_name' => 'Test Customer',
            'customer_type' => 'Retail',
        ]);

        // Weight category: base unit g, with 1 Kg = 1000 g both ways (what
        // UnitConversionService::saveRates() writes).
        $weight     = UnitCategory::create(['name' => 'Weight']);
        $this->gram = UnitType::create(['unit_category_id' => $weight->id, 'name' => 'Gram', 'symbol' => 'g']);
        $this->kg   = UnitType::create(['unit_category_id' => $weight->id, 'name' => 'Kilogram', 'symbol' => 'Kg']);
        $weight->update(['base_unit_type_id' => $this->gram->id]);

        $this->setRate($this->kg, $this->gram, 1000);
        $this->setRate($this->gram, $this->kg, 0.001);

        // A second category, to prove cross-category conversion is refused.
        $volume      = UnitCategory::create(['name' => 'Volume']);
        $this->litre = UnitType::create(['unit_category_id' => $volume->id, 'name' => 'Litre', 'symbol' => 'L']);
    }

    private function setRate(UnitType $from, UnitType $to, float $multiplier): void
    {
        UnitConversion::create([
            'from_unit_type_id' => $from->id,
            'to_unit_type_id'   => $to->id,
            'multiplier'        => $multiplier,
        ]);
    }

    /** A product stocked in grams. */
    private function gramStockedProduct(array $overrides = []): Product
    {
        return Product::factory()->create(['base_unit_type_id' => $this->gram->id] + $overrides);
    }

    /** @return array{0: int, 1: int} [storeId, locationId] */
    private function makeStoreAndLocation(): array
    {
        static $seq = 0;
        $seq++;

        $locationId = DB::table('inv_locations')->insertGetId([
            'company_id'          => 1,
            'industry_id'         => 1,
            'location_code'       => "LOC-{$seq}",
            'location_name'       => "Test Location {$seq}",
            'country'             => 'LK',
            'loc_street_address'  => 'Street',
            'loc_city'            => 'City',
            'loc_country'         => 'LK',
            'loc_state'           => 'State',
            'loc_postal_zip_code' => '00000',
            'financial_year'      => '2026',
            'stock_releasing_method' => 'FIFO',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $storeId = DB::table('inv_stores')->insertGetId([
            'store_type_id' => 1,
            'location_id'   => $locationId,
            'store_code'    => "STR-{$seq}",
            'store_name'    => "Test Store {$seq}",
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return [$storeId, $locationId];
    }

    /**
     * A draft GRN receiving $qty of $product in $unit, as one roll, ready to confirm.
     *
     * @return array{grn: GoodsReceivedNote, item: GoodsReceivedNoteItem, piece: GrnItemPiece}
     */
    private function makeDraftGrn(
        Product $product,
        float $qty,
        ?UnitType $unit,
        ?int $storeId = null,
        ?int $locationId = null,
    ): array {
        static $seq = 0;
        $seq++;

        $grn = GoodsReceivedNote::create([
            'grn_no'      => sprintf('GRN-UOM-%04d', $seq),
            'grn_date'    => '2026-07-01',
            'status'      => 'draft',
            'store_id'    => $storeId,
            'location_id' => $locationId,
        ]);

        $item = GoodsReceivedNoteItem::create([
            'grn_id'            => $grn->id,
            'product_id'        => $product->id,
            'unit_id'           => $unit?->id,
            'quantity_received' => $qty,
            'unit_price'        => 250,
        ]);

        // One roll carrying the whole line, weighed in the line's UOM.
        $piece = GrnItemPiece::create([
            'grn_item_id' => $item->id,
            'grn_id'      => $grn->id,
            'product_id'  => $product->id,
            'store_id'    => $storeId,
            'location_id' => $locationId,
            'piece_no'    => 1,
            'weight'      => $qty,
            'status'      => GrnItemPiece::STATUS_DRAFT,
        ]);

        return ['grn' => $grn, 'item' => $item, 'piece' => $piece];
    }

    private function confirmGrn(GoodsReceivedNote $grn): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->postJson("/api/v1/goods-received-notes/{$grn->id}/confirm");
    }

    // ── GRN: inbound conversion ─────────────────────────────────────────────

    public function test_grn_in_kg_posts_stock_in_the_products_base_uom_of_grams(): void
    {
        $product              = $this->gramStockedProduct();
        [$storeId, $locId]    = $this->makeStoreAndLocation();
        $seed                 = $this->makeDraftGrn($product, 100, $this->kg, $storeId, $locId);

        $this->confirmGrn($seed['grn'])->assertOk();

        // 100 Kg received → 100,000 g on hand
        $this->assertSame(100000.0, (float) ProductLocationStore::where('product_id', $product->id)
            ->where('store_id', $storeId)
            ->value('current_stock'));

        $ledger = StockTransaction::where('product_id', $product->id)->sole();
        $this->assertSame(100000.0, (float) $ledger->qty_in);
        $this->assertSame($this->gram->id, (int) $ledger->unit_id, 'ledger must be denominated in the base UOM');

        // The price is rebased along with the quantity: 250 per Kg is 0.25 per g.
        // If it were not, qty_in × unit_price — which is exactly how the valuation
        // report and the WAC subquery value stock — would come out 1000× too high.
        $this->assertSame(0.25, (float) $ledger->unit_price);
        $this->assertSame(25000.0, (float) $ledger->qty_in * (float) $ledger->unit_price);

        // The line still records what the supplier actually invoiced, plus the
        // factor used — so a later rate edit cannot rewrite this history.
        $item = $seed['item']->fresh();
        $this->assertSame(100.0, (float) $item->quantity_received);
        $this->assertSame($this->kg->id, (int) $item->unit_id);
        $this->assertSame(250.0, (float) $item->unit_price, 'the line keeps the invoiced per-Kg price');
        $this->assertSame(1000.0, (float) $item->conversion_factor);
        $this->assertSame(100000.0, (float) $item->base_quantity);
    }

    public function test_roll_weights_are_sealed_in_the_base_uom(): void
    {
        $product           = $this->gramStockedProduct();
        [$storeId, $locId] = $this->makeStoreAndLocation();
        $seed              = $this->makeDraftGrn($product, 1.5, $this->kg, $storeId, $locId);

        $this->confirmGrn($seed['grn'])->assertOk();

        // A 1.5 Kg roll is 1500 g — DeliveryOrderService sums these weights to
        // decide how much to subtract from current_stock, so they must be base.
        $this->assertSame(1500.0, (float) $seed['piece']->fresh()->weight);
    }

    /**
     * A roll is stock, so it is stored in the stocking UOM from the first draft save —
     * but the GRN is a document in the supplier's UOM, so the roll editor must get the
     * Kg the user typed back, not the grams they became. Reading grams under a "(Kg)"
     * heading is the bug this pins: 1.5 Kg came back as 1,500.
     */
    public function test_the_roll_editor_reads_roll_weights_back_in_the_lines_uom(): void
    {
        [$storeId, $locId] = $this->makeStoreAndLocation();
        $product           = $this->gramStockedProduct();

        $supplier = SupplierMaster::create([
            'supplier_code' => 'SUP-ROLL-1',
            'supplier_name' => 'Roll Supplier',
        ]);

        $grnId = $this->actingAs($this->user)
            ->postJson('/api/v1/goods-received-notes', [
                'grn_date'      => '2026-07-01',
                'supplier_id'   => $supplier->id,
                'shipping_code' => 'SHIP-ROLL-1',
                'store_id'      => $storeId,
                'location_id'   => $locId,
                'items'         => [[
                    'product_id'        => $product->id,
                    'unit_id'           => $this->kg->id,
                    'quantity_received' => 5,
                    'unit_price'        => 250,
                    'rolls'             => [
                        ['roll_no' => 'R-1', 'weight' => 2],
                        ['roll_no' => 'R-2', 'weight' => 3],
                    ],
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $storedWeights = fn () => GrnItemPiece::where('grn_id', $grnId)
            ->orderBy('piece_no')
            ->pluck('weight')
            ->map(fn ($w) => (float) $w)
            ->all();

        // Stored as stock — grams — from the draft save, not only once confirmed.
        $this->assertSame([2000.0, 3000.0], $storedWeights());

        $this->confirmGrn(GoodsReceivedNote::findOrFail($grnId))->assertOk();
        $this->assertSame([2000.0, 3000.0], $storedWeights(), 'confirming must not convert twice');

        // Read back for the roll editor: the Kg the user typed, so that its
        // Σ rolls = Qty Received balance still holds.
        $items = $this->actingAs($this->user)
            ->getJson("/api/v1/goods-received-notes/{$grnId}")
            ->assertOk()
            ->json('data.items.0');

        $this->assertSame(5.0, (float) $items['quantity_received']);
        $this->assertSame([2.0, 3.0], array_map('floatval', array_column($items['pieces'], 'weight')));
    }

    /**
     * Cutting a roll to fill a partial sale spawns an offcut against the same GRN line.
     * The GRN records what was RECEIVED, so the offcut must not show up there — it would
     * both invent a roll the supplier never sent and break Σ rolls = Qty Received.
     */
    public function test_an_offcut_from_a_partial_sale_is_not_shown_as_a_received_roll(): void
    {
        [$storeId, $locId] = $this->makeStoreAndLocation();
        $product           = $this->gramStockedProduct();
        $seed              = $this->makeDraftGrn($product, 2, $this->kg, $storeId, $locId);

        $this->confirmGrn($seed['grn'])->assertOk();

        // Sell 1.2 Kg off the 2 Kg roll — 800 g comes back as a fresh, labelled offcut.
        $offcut = app(RollService::class)->shipOrCut($seed['piece']->fresh(), 1200.0);
        $this->assertNotNull($offcut);
        $this->assertSame($seed['item']->id, (int) $offcut->grn_item_id);

        $pieces = $this->actingAs($this->user)
            ->getJson("/api/v1/goods-received-notes/{$seed['grn']->id}")
            ->assertOk()
            ->json('data.items.0.pieces');

        $this->assertCount(1, $pieces, 'the GRN shows the roll it received, not the one sales cut');
        $this->assertSame(2.0, (float) $pieces[0]['weight']);
    }

    public function test_line_total_uses_the_invoiced_price_not_the_rebased_one(): void
    {
        [$storeId, $locId] = $this->makeStoreAndLocation();
        $product           = $this->gramStockedProduct();

        $supplier = SupplierMaster::create([
            'supplier_code' => 'SUP-0001',
            'supplier_name' => 'Test Supplier',
        ]);

        // Saved through the real endpoint so syncItems() computes the total.
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/goods-received-notes', [
                'grn_date'      => '2026-07-01',
                'supplier_id'   => $supplier->id,
                'shipping_code' => 'SHIP-UOM-1',
                'store_id'      => $storeId,
                'location_id'   => $locId,
                'items'         => [[
                    'product_id'        => $product->id,
                    'unit_id'           => $this->kg->id,
                    'quantity_received' => 100,
                    'unit_price'        => 250,
                    'rolls'             => [['roll_no' => 'R-1', 'weight' => 100]],
                ]],
            ]);
        $response->assertCreated();

        $item = GoodsReceivedNoteItem::where('grn_id', $response->json('data.id'))->sole();

        // 100 Kg × 250/Kg — what the supplier billed. NOT 100,000 g × 250.
        $this->assertSame(25000.0, (float) $item->line_total);
        $this->assertSame(100000.0, (float) $item->base_quantity);
    }

    public function test_grn_in_base_uom_is_unchanged(): void
    {
        $product           = $this->gramStockedProduct();
        [$storeId, $locId] = $this->makeStoreAndLocation();
        $seed              = $this->makeDraftGrn($product, 250, $this->gram, $storeId, $locId);

        $this->confirmGrn($seed['grn'])->assertOk();

        $this->assertSame(250.0, (float) ProductLocationStore::where('product_id', $product->id)->value('current_stock'));
        $this->assertSame(1.0, (float) $seed['item']->fresh()->conversion_factor);
    }

    // ── The reported bug ────────────────────────────────────────────────────

    public function test_buy_in_kg_sell_in_grams_leaves_a_correct_balance(): void
    {
        $product           = $this->gramStockedProduct();
        [$storeId, $locId] = $this->makeStoreAndLocation();
        $seed              = $this->makeDraftGrn($product, 100, $this->kg, $storeId, $locId);

        $this->confirmGrn($seed['grn'])->assertOk();

        // Sell one roll (the whole 100 Kg = 100,000 g) — the roll IS the stock.
        $piece = $seed['piece']->fresh();

        $soResponse = $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', [
                'order_date'      => '2026-07-09',
                'customer_id'     => $this->customer->id,
                'sales_person_id' => $this->user->id,
                'status'          => 'confirmed',
                'items'           => [[
                    'product_id'  => $product->id,
                    'unit_id'     => $this->gram->id,
                    'unit_price'  => 2,
                    'piece_codes' => [$piece->piece_code],
                ]],
            ]);
        $soResponse->assertCreated();

        $so     = SalesOrder::findOrFail($soResponse->json('data.id'));
        $soItem = $so->items()->sole();

        // The scanned line's quantity is the roll weight, in the stocking UOM.
        $this->assertSame(100000.0, (float) $soItem->quantity);

        $doResponse = $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', [
                'so_id'         => $so->id,
                'delivery_date' => '2026-07-10',
                'items'         => [[
                    'so_item_id' => $soItem->id,
                    'piece_ids'  => [$piece->id],
                ]],
            ]);
        $doResponse->assertCreated();

        // Before the fix this aborted with "Insufficient stock" (100 - 100000 < 0).
        $this->actingAs($this->user)
            ->patchJson("/api/v1/delivery-orders/{$doResponse->json('data.id')}/status", ['status' => 'confirmed'])
            ->assertOk();

        $this->assertSame(0.0, (float) ProductLocationStore::where('product_id', $product->id)
            ->where('store_id', $storeId)
            ->value('current_stock'));

        $out = StockTransaction::where('product_id', $product->id)->where('qty_out', '>', 0)->sole();
        $this->assertSame(100000.0, (float) $out->qty_out);
        $this->assertSame($this->gram->id, (int) $out->unit_id);
    }

    public function test_manual_sale_in_kg_from_gram_stock_deducts_the_converted_quantity(): void
    {
        $product           = $this->gramStockedProduct();
        [$storeId, $locId] = $this->makeStoreAndLocation();

        // Seed 100,000 g of stock with no rolls, so a manual (typed-qty) line is allowed.
        ProductLocationStore::create([
            'product_id'    => $product->id,
            'store_id'      => $storeId,
            'location_id'   => $locId,
            'current_stock' => 100000,
        ]);

        // Sell 2 Kg of a gram-stocked product.
        $soResponse = $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', [
                'order_date'      => '2026-07-09',
                'customer_id'     => $this->customer->id,
                'sales_person_id' => $this->user->id,
                'status'          => 'confirmed',
                'items'           => [[
                    'product_id' => $product->id,
                    'unit_id'    => $this->kg->id,
                    'quantity'   => 2,
                    'unit_price' => 1500,
                ]],
            ]);
        $soResponse->assertCreated();

        $so     = SalesOrder::findOrFail($soResponse->json('data.id'));
        $soItem = $so->items()->sole();

        $this->assertSame(2.0, (float) $soItem->quantity, 'the line keeps the UOM the user typed');
        $this->assertSame(2000.0, (float) $soItem->base_quantity);
        $this->assertSame(1000.0, (float) $soItem->conversion_factor);

        $doResponse = $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', [
                'so_id'         => $so->id,
                'delivery_date' => '2026-07-10',
                'store_id'      => $storeId,
                'location_id'   => $locId,
                'items'         => [[
                    'so_item_id' => $soItem->id,
                    'quantity'   => 2,
                ]],
            ]);
        $doResponse->assertCreated();

        $this->actingAs($this->user)
            ->patchJson("/api/v1/delivery-orders/{$doResponse->json('data.id')}/status", ['status' => 'confirmed'])
            ->assertOk();

        // 100,000 g - 2 Kg = 98,000 g
        $this->assertSame(98000.0, (float) ProductLocationStore::where('product_id', $product->id)
            ->where('store_id', $storeId)
            ->value('current_stock'));
    }

    // ── Guard rails ─────────────────────────────────────────────────────────

    public function test_cross_category_uom_is_rejected(): void
    {
        $product           = $this->gramStockedProduct();
        [$storeId, $locId] = $this->makeStoreAndLocation();
        $seed              = $this->makeDraftGrn($product, 10, $this->litre, $storeId, $locId);

        $this->confirmGrn($seed['grn'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot convert L to g — they belong to different unit categories.');
    }

    public function test_missing_conversion_rate_is_rejected_rather_than_assumed(): void
    {
        UnitConversion::where('from_unit_type_id', $this->kg->id)->delete();

        $product           = $this->gramStockedProduct();
        [$storeId, $locId] = $this->makeStoreAndLocation();
        $seed              = $this->makeDraftGrn($product, 100, $this->kg, $storeId, $locId);

        $this->confirmGrn($seed['grn'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No conversion defined from Kg to g — set the rate in Unit Conversions.');

        // Nothing was posted — a missing rate must never fall back to 1:1.
        $this->assertSame(0, StockTransaction::where('product_id', $product->id)->count());
    }

    public function test_product_without_a_stocking_uom_cannot_move_stock(): void
    {
        $product           = Product::factory()->create(['base_unit_type_id' => null]);
        [$storeId, $locId] = $this->makeStoreAndLocation();
        $seed              = $this->makeDraftGrn($product, 100, $this->kg, $storeId, $locId);

        $this->confirmGrn($seed['grn'])
            ->assertStatus(422)
            ->assertJsonPath('message', sprintf(
                'Product "%s" has no Stocking UOM — set it on the product before moving stock.',
                $product->name,
            ));
    }
}
