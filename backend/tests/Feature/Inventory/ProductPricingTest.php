<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GoodsReceivedNoteItem;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\SalesChannel;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Selling price ≠ GRN cost: SO defaults come from the product's price list
 * (inv_product_sales_channels.selling_price); GRN confirm mirrors the
 * purchase cost onto the price list's cost_price (last-cost method) without
 * ever touching selling prices.
 */
class ProductPricingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['view_sales_orders', 'create_sales_orders', 'view_grns', 'create_grns', 'confirm_grns'] as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo(['view_sales_orders', 'create_sales_orders', 'view_grns', 'create_grns', 'confirm_grns']);
    }

    /** Attach a price-list (sales-channel pivot) row to a product. */
    private function givePriceList(
        Product $product,
        ?float $sellingPrice,
        ?float $costPrice = null,
        ?int $unitTypeId = null,
    ): int {
        $channel = SalesChannel::firstOrCreate(['sales_channel_name' => 'Default']);

        return (int) DB::table('inv_product_sales_channels')->insertGetId([
            'product_id'       => $product->id,
            'sales_channel_id' => $channel->id,
            'unit_type_id'     => $unitTypeId,
            'cost_price'       => $costPrice,
            'selling_price'    => $sellingPrice,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    /** Seed a GRN with one item + sealed pieces (mirrors SalesOrderTest helper). */
    private function makeGrn(
        Product $product,
        float $unitPrice,
        string $grnStatus = 'confirmed',
        string $pieceStatus = GrnItemPiece::STATUS_IN_STOCK,
    ): array {
        static $seq = 0;
        $seq++;

        $grn = GoodsReceivedNote::create([
            'grn_no'   => sprintf('GRN-PR-%04d', $seq),
            'grn_date' => '2026-07-01',
            'status'   => $grnStatus,
        ]);

        $item = GoodsReceivedNoteItem::create([
            'grn_id'            => $grn->id,
            'product_id'        => $product->id,
            'quantity_received' => 10,
            'unit_price'        => $unitPrice,
        ]);

        $piece = GrnItemPiece::create([
            'grn_item_id' => $item->id,
            'grn_id'      => $grn->id,
            'product_id'  => $product->id,
            'piece_no'    => 1,
            'weight'      => 10,
            'piece_code'  => sprintf('%s-I001-P001', $grn->grn_no),
            'status'      => $pieceStatus,
        ]);

        return ['grn' => $grn, 'item' => $item, 'piece' => $piece];
    }

    // ── product-price endpoint ──────────────────────────────────────────────

    public function test_product_price_returns_price_list_selling_price_and_latest_grn_cost(): void
    {
        $product = Product::factory()->create();
        $this->givePriceList($product, sellingPrice: 1300, costPrice: 1000);
        $this->makeGrn($product, unitPrice: 1100);
        // Later draft GRN must not become the cost basis
        $this->makeGrn($product, unitPrice: 9999, grnStatus: 'draft', pieceStatus: GrnItemPiece::STATUS_DRAFT);

        $this->actingAs($this->user)
            ->getJson("/api/v1/sales-orders/product-price/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.unit_price', 1300)
            ->assertJsonPath('data.cost_price', 1100);
    }

    public function test_product_price_selling_is_null_without_price_list(): void
    {
        $product = Product::factory()->create();
        $this->makeGrn($product, unitPrice: 200);

        $this->actingAs($this->user)
            ->getJson("/api/v1/sales-orders/product-price/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.unit_price', null)
            ->assertJsonPath('data.cost_price', 200);
    }

    // ── scan-piece carries the selling price ────────────────────────────────

    public function test_scan_piece_returns_selling_price_alongside_grn_cost(): void
    {
        $product = Product::factory()->create();
        $this->givePriceList($product, sellingPrice: 1300);
        $seed = $this->makeGrn($product, unitPrice: 1100);

        $this->actingAs($this->user)
            ->getJson('/api/v1/sales-orders/scan-piece/' . $seed['piece']->piece_code)
            ->assertOk()
            ->assertJsonPath('data.grn_unit_price', 1100)
            ->assertJsonPath('data.selling_price', 1300);
    }

    public function test_available_pieces_include_selling_price(): void
    {
        $product = Product::factory()->create();
        $this->givePriceList($product, sellingPrice: 1300);
        $this->makeGrn($product, unitPrice: 1100);

        $this->actingAs($this->user)
            ->getJson("/api/v1/sales-orders/available-pieces/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.selling_price', 1300)
            ->assertJsonPath('data.pieces.0.grn_unit_price', 1100);
    }

    // ── Shipment-specific pricing (confirmed costing wins over price list) ──

    /** Attach a confirmed costing pricing the given GRN item at $sellingPrice. */
    private function giveConfirmedCosting(int $grnId, int $grnItemId, int $productId, float $sellingPrice): void
    {
        $costingId = DB::table('inv_costings')->insertGetId([
            'document_no'  => 'CST-T-' . $grnItemId,
            'reference_no' => 'CRef-T-' . $grnItemId,
            'supplier_id'  => 1,
            'costing_type' => 'fob',
            'status'       => 'confirmed',
            'confirmed_at' => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('inv_costing_items')->insert([
            'costing_id'         => $costingId,
            'grn_id'             => $grnId,
            'grn_item_id'        => $grnItemId,
            'product_id'         => $productId,
            'quantity'           => 10,
            'conversion_factor'  => 1,
            'base_quantity'      => 10,
            'unit_price'         => 0,
            'landed_unit_cost'   => 0,
            'selling_price'      => $sellingPrice,
            // Pricing reads the stocking-UOM figure — factor 1, same number
            'selling_price_base' => $sellingPrice,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    public function test_scan_piece_prefers_confirmed_costing_price_over_price_list(): void
    {
        $product = Product::factory()->create();
        $this->givePriceList($product, sellingPrice: 1300);
        $seed = $this->makeGrn($product, unitPrice: 1000);
        $this->giveConfirmedCosting($seed['grn']->id, $seed['item']->id, $product->id, 1450.0);

        $this->actingAs($this->user)
            ->getJson('/api/v1/sales-orders/scan-piece/' . $seed['piece']->piece_code)
            ->assertOk()
            ->assertJsonPath('data.selling_price', 1450)
            ->assertJsonPath('data.price_source', 'costing');
    }

    public function test_scan_piece_falls_back_to_price_list_when_grn_not_costed(): void
    {
        $product = Product::factory()->create();
        $this->givePriceList($product, sellingPrice: 1300);
        $seed = $this->makeGrn($product, unitPrice: 1000);

        $this->actingAs($this->user)
            ->getJson('/api/v1/sales-orders/scan-piece/' . $seed['piece']->piece_code)
            ->assertOk()
            ->assertJsonPath('data.selling_price', 1300)
            ->assertJsonPath('data.price_source', 'price_list');
    }

    public function test_available_pieces_price_each_roll_from_its_own_shipment(): void
    {
        $product = Product::factory()->create();
        $this->givePriceList($product, sellingPrice: 1300);
        $old = $this->makeGrn($product, unitPrice: 1000); // costed at 1450
        $new = $this->makeGrn($product, unitPrice: 1100); // uncosted → price list
        $this->giveConfirmedCosting($old['grn']->id, $old['item']->id, $product->id, 1450.0);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/sales-orders/available-pieces/{$product->id}")
            ->assertOk();

        $pieces = collect($response->json('data.pieces'))->keyBy('piece_code');
        $this->assertEquals(1450, $pieces[$old['piece']->piece_code]['selling_price']);
        $this->assertSame('costing', $pieces[$old['piece']->piece_code]['price_source']);
        $this->assertEquals(1300, $pieces[$new['piece']->piece_code]['selling_price']);
        $this->assertSame('price_list', $pieces[$new['piece']->piece_code]['price_source']);
    }

    // ── GRN confirm syncs last cost onto the price list ─────────────────────

    public function test_grn_confirm_updates_price_list_cost_and_never_selling_price(): void
    {
        $product = Product::factory()->create();
        $pivotId = $this->givePriceList($product, sellingPrice: 1300, costPrice: 1000);
        $seed    = $this->makeGrn($product, unitPrice: 1150, grnStatus: 'draft', pieceStatus: GrnItemPiece::STATUS_DRAFT);

        $this->actingAs($this->user)
            ->postJson("/api/v1/goods-received-notes/{$seed['grn']->id}/confirm")
            ->assertOk();

        $pivot = DB::table('inv_product_sales_channels')->find($pivotId);
        $this->assertSame(1150.0, (float) $pivot->cost_price);
        $this->assertSame(1300.0, (float) $pivot->selling_price);
    }

    public function test_grn_confirm_without_price_list_rows_still_confirms(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrn($product, unitPrice: 500, grnStatus: 'draft', pieceStatus: GrnItemPiece::STATUS_DRAFT);

        $this->actingAs($this->user)
            ->postJson("/api/v1/goods-received-notes/{$seed['grn']->id}/confirm")
            ->assertOk();

        $this->assertSame('confirmed', GoodsReceivedNote::find($seed['grn']->id)->status->value);
    }
}
