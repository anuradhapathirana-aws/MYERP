<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Attribute;
use Modules\Inventory\Models\AttributeType;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GoodsReceivedNoteItem;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductLocationStore;
use Modules\Inventory\Models\SalesOrder;
use Modules\Inventory\Models\SalesOrderPiece;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SalesOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private CustomerMaster $customer;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['view_sales_orders', 'create_sales_orders', 'edit_sales_orders', 'delete_sales_orders'] as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo(['view_sales_orders', 'create_sales_orders', 'edit_sales_orders', 'delete_sales_orders']);

        $this->customer = CustomerMaster::create([
            'customer_code' => 'CUS-0001',
            'customer_name' => 'Test Customer',
            'customer_type' => 'Retail',
        ]);
    }

    /** Give a product physical stock — non-serial products need it to go on a sales order. */
    private function seedStock(Product $product, float $stock = 1000): void
    {
        ProductLocationStore::create([
            'product_id'    => $product->id,
            'location_id'   => null,
            'store_id'      => null,
            'current_stock' => $stock,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function validPayload(array $overrides = []): array
    {
        $product = Product::factory()->create();
        $this->seedStock($product);

        return array_merge([
            'order_date'      => '2026-07-08',
            'customer_id'     => $this->customer->id,
            'customer_type'   => 'Retail',
            'sales_person_id' => $this->user->id,
            'items'           => [[
                'product_id' => $product->id,
                'quantity'   => 10,
                'unit_price' => 100,
                'discount'   => 0,
                'tax'        => 0,
            ]],
        ], $overrides);
    }

    /**
     * Seed a GRN (default confirmed) with weighted, sealed pieces for a product.
     *
     * @param array<float> $weights
     * @return array{grn: GoodsReceivedNote, item: GoodsReceivedNoteItem, pieces: array<GrnItemPiece>}
     */
    private function makeColorAttribute(string $name): Attribute
    {
        $type = AttributeType::firstOrCreate(
            ['attribute_type_name' => 'Color'],
            ['category_id' => Category::firstOrCreate(['category_name' => 'Test Category'])->id],
        );

        return Attribute::create(['attribute_type_id' => $type->id, 'attribute_name' => $name]);
    }

    private function makeGrnWithPieces(
        Product $product,
        array $weights,
        string $grnStatus = 'confirmed',
        string $pieceStatus = GrnItemPiece::STATUS_IN_STOCK,
        float $unitPrice = 250.0,
        ?int $attributeId = null,
    ): array {
        static $seq = 0;
        $seq++;

        $grn = GoodsReceivedNote::create([
            'grn_no'   => sprintf('GRN-2026-%04d', $seq),
            'grn_date' => '2026-07-01',
            'status'   => $grnStatus,
        ]);

        $item = GoodsReceivedNoteItem::create([
            'grn_id'            => $grn->id,
            'product_id'        => $product->id,
            'attribute_id'      => $attributeId,
            'quantity_received' => array_sum($weights),
            'unit_price'        => $unitPrice,
        ]);

        $pieces = [];
        foreach ($weights as $i => $weight) {
            $pieces[] = GrnItemPiece::create([
                'grn_item_id' => $item->id,
                'grn_id'      => $grn->id,
                'product_id'  => $product->id,
                'piece_no'    => $i + 1,
                'weight'      => $weight,
                'piece_code'  => sprintf('%s-I001-P%03d', $grn->grn_no, $i + 1),
                'status'      => $pieceStatus,
            ]);
        }

        return ['grn' => $grn, 'item' => $item, 'pieces' => $pieces];
    }

    // ── Auth & permissions ──────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/sales-orders')->assertUnauthorized();
    }

    public function test_user_without_view_permission_is_forbidden(): void
    {
        $restricted = User::factory()->create(['active_modules' => ['inventory']]);

        $this->actingAs($restricted)
            ->getJson('/api/v1/sales-orders')
            ->assertForbidden();
    }

    public function test_users_all_is_reachable_by_non_admin(): void
    {
        $restricted = User::factory()->create(['active_modules' => ['inventory']]);

        $this->actingAs($restricted)
            ->getJson('/api/users/all')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name']]]);
    }

    // ── Index ───────────────────────────────────────────────────────────────

    public function test_index_returns_paginated_list(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload())
            ->assertCreated();

        $this->actingAs($this->user)
            ->getJson('/api/v1/sales-orders')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'so_no', 'status', 'status_label', 'grand_total', 'customer']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 1);
    }

    // ── Store ───────────────────────────────────────────────────────────────

    public function test_store_creates_draft_with_generated_so_no_and_totals(): void
    {
        $year    = now()->year;
        $product = Product::factory()->create();
        $this->seedStock($product);

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload([
                'transport_charge' => 50,
                'items'            => [[
                    'product_id' => $product->id,
                    'quantity'   => 10,
                    'unit_price' => 100,
                    'discount'   => 10,
                    'tax'        => 5,
                ]],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.so_no', "SO-{$year}-0001")
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.subtotal', 1000)
            // 1000 - 100 (disc) + 50 (tax) = 950 line total, + 50 transport
            ->assertJsonPath('data.grand_total', 1000);
    }

    public function test_store_with_confirmed_status_persists_confirmed(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload(['status' => 'confirmed']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed');
    }

    public function test_sequential_creates_get_consecutive_so_numbers(): void
    {
        $year = now()->year;

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload())
            ->assertJsonPath('data.so_no', "SO-{$year}-0001");

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload())
            ->assertJsonPath('data.so_no', "SO-{$year}-0002");
    }

    public function test_store_with_scanned_pieces_defaults_to_selling_the_rolls_whole(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrnWithPieces($product, [12.5, 7.5], unitPrice: 300);
        $codes   = array_map(fn (GrnItemPiece $p) => $p->piece_code, $seed['pieces']);

        // No quantity sent — scanning rolls means "sell these rolls", so the line takes
        // them whole. The user can then reduce it to sell only part of the last roll.
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload([
                'items' => [[
                    'product_id'  => $product->id,
                    'unit_price'  => 300,
                    'piece_codes' => $codes,
                ]],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.items.0.is_scanned', true)
            ->assertJsonPath('data.items.0.quantity', 20);

        $soId = $response->json('data.id');

        $this->assertSame(2, SalesOrderPiece::where('so_id', $soId)->count());
        $this->assertSame(
            2,
            GrnItemPiece::whereIn('piece_code', $codes)
                ->where('status', GrnItemPiece::STATUS_ALLOCATED)
                ->count(),
        );
        $this->assertEquals(300.0, (float) SalesOrderPiece::where('so_id', $soId)->first()->grn_unit_price);

        // Every roll is taken in full, so nothing will be cut at dispatch.
        SalesOrderPiece::where('so_id', $soId)->get()->each(
            fn (SalesOrderPiece $sp) => $this->assertEquals((float) $sp->weight, (float) $sp->taken_quantity),
        );
    }

    public function test_store_rejects_a_quantity_the_selected_rolls_cannot_cover(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrnWithPieces($product, [12.5, 7.5], unitPrice: 300);
        $codes   = array_map(fn (GrnItemPiece $p) => $p->piece_code, $seed['pieces']);

        // The rolls hold 20; selling 999 off them is not possible.
        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload([
                'items' => [[
                    'product_id'  => $product->id,
                    'quantity'    => 999,
                    'unit_price'  => 300,
                    'piece_codes' => $codes,
                ]],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'The selected rolls hold only 20 — add another roll or reduce the quantity.');
    }

    public function test_store_rejects_piece_that_is_not_in_stock(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrnWithPieces($product, [10], grnStatus: 'draft', pieceStatus: GrnItemPiece::STATUS_DRAFT);

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload([
                'items' => [[
                    'product_id'  => $product->id,
                    'unit_price'  => 300,
                    'piece_codes' => [$seed['pieces'][0]->piece_code],
                ]],
            ]))
            ->assertStatus(422);
    }

    public function test_store_rejects_piece_already_allocated_to_another_order(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrnWithPieces($product, [10]);
        $code    = $seed['pieces'][0]->piece_code;

        $payload = $this->validPayload([
            'items' => [[
                'product_id'  => $product->id,
                'unit_price'  => 300,
                'piece_codes' => [$code],
            ]],
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/sales-orders', $payload)->assertCreated();
        $this->actingAs($this->user)->postJson('/api/v1/sales-orders', $payload)->assertStatus(422);
    }

    public function test_store_rejects_product_not_allowed_for_direct_sale(): void
    {
        $product = Product::factory()->create(['not_allow_direct_sale' => true]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload([
                'items' => [[
                    'product_id' => $product->id,
                    'quantity'   => 5,
                    'unit_price' => 100,
                ]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.product_id']);
    }

    public function test_store_rejects_invalid_discount_and_transport(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload([
                'transport_charge' => -5,
                'items'            => [[
                    'product_id' => Product::factory()->create()->id,
                    'quantity'   => 5,
                    'unit_price' => 100,
                    'discount'   => 101,
                ]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['transport_charge', 'items.0.discount']);
    }

    // ── Update ──────────────────────────────────────────────────────────────

    public function test_update_swapping_pieces_releases_old_and_allocates_new(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrnWithPieces($product, [10, 15]);
        [$first, $second] = $seed['pieces'];

        $soId = $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload([
                'items' => [[
                    'product_id'  => $product->id,
                    'unit_price'  => 300,
                    'piece_codes' => [$first->piece_code],
                ]],
            ]))->json('data.id');

        $this->actingAs($this->user)
            ->putJson("/api/v1/sales-orders/{$soId}", $this->validPayload([
                'items' => [[
                    'product_id'  => $product->id,
                    'unit_price'  => 300,
                    'piece_codes' => [$second->piece_code],
                ]],
            ]))
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 15);

        $this->assertSame(GrnItemPiece::STATUS_IN_STOCK, $first->fresh()->status);
        $this->assertSame(GrnItemPiece::STATUS_ALLOCATED, $second->fresh()->status);
    }

    // ── Status transitions ──────────────────────────────────────────────────

    public function test_status_transitions_follow_the_allowed_map(): void
    {
        $soId = $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload())
            ->json('data.id');

        // draft → completed is illegal
        $this->actingAs($this->user)
            ->patchJson("/api/v1/sales-orders/{$soId}/status", ['status' => 'completed'])
            ->assertStatus(422);

        $this->actingAs($this->user)
            ->patchJson("/api/v1/sales-orders/{$soId}/status", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        // confirmed → completed is never manual — the SO completes automatically
        // when its confirmed DOs have delivered every line in full
        $this->actingAs($this->user)
            ->patchJson("/api/v1/sales-orders/{$soId}/status", ['status' => 'completed'])
            ->assertStatus(422);
    }

    public function test_cancelling_releases_allocated_pieces(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrnWithPieces($product, [10]);
        $piece   = $seed['pieces'][0];

        $soId = $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload([
                'status' => 'confirmed',
                'items'  => [[
                    'product_id'  => $product->id,
                    'unit_price'  => 300,
                    'piece_codes' => [$piece->piece_code],
                ]],
            ]))->json('data.id');

        $this->assertSame(GrnItemPiece::STATUS_ALLOCATED, $piece->fresh()->status);

        $this->actingAs($this->user)
            ->patchJson("/api/v1/sales-orders/{$soId}/status", ['status' => 'cancelled'])
            ->assertOk();

        $this->assertSame(GrnItemPiece::STATUS_IN_STOCK, $piece->fresh()->status);
        $this->assertSame(0, SalesOrderPiece::where('so_id', $soId)->count());
    }

    // ── Delete ──────────────────────────────────────────────────────────────

    public function test_deleting_draft_releases_pieces_and_soft_deletes(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrnWithPieces($product, [10]);
        $piece   = $seed['pieces'][0];

        $soId = $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload([
                'items' => [[
                    'product_id'  => $product->id,
                    'unit_price'  => 300,
                    'piece_codes' => [$piece->piece_code],
                ]],
            ]))->json('data.id');

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/sales-orders/{$soId}")
            ->assertNoContent();

        $this->assertSame(GrnItemPiece::STATUS_IN_STOCK, $piece->fresh()->status);
        $this->assertSoftDeleted('inv_sales_orders', ['id' => $soId]);
    }

    public function test_deleting_confirmed_order_is_rejected(): void
    {
        $soId = $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload(['status' => 'confirmed']))
            ->json('data.id');

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/sales-orders/{$soId}")
            ->assertStatus(422);

        $this->assertNotNull(SalesOrder::find($soId));
    }

    // ── Scan & helpers ──────────────────────────────────────────────────────

    public function test_scan_piece_returns_product_and_grn_price(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrnWithPieces($product, [12.5], unitPrice: 275);

        $this->actingAs($this->user)
            ->getJson('/api/v1/sales-orders/scan-piece/' . $seed['pieces'][0]->piece_code)
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.product.id', $product->id)
            ->assertJsonPath('data.grn_unit_price', 275)
            ->assertJsonPath('data.piece.weight', 12.5);
    }

    public function test_scan_piece_flags_allocated_piece_as_unavailable(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrnWithPieces($product, [10], pieceStatus: GrnItemPiece::STATUS_ALLOCATED);

        $this->actingAs($this->user)
            ->getJson('/api/v1/sales-orders/scan-piece/' . $seed['pieces'][0]->piece_code)
            ->assertOk()
            ->assertJsonPath('data.available', false);
    }

    public function test_scan_piece_returns_404_for_unknown_code(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/sales-orders/scan-piece/UNKNOWN-CODE')
            ->assertNotFound();
    }

    public function test_next_so_no_and_order_sources_endpoints(): void
    {
        $year = now()->year;

        $this->actingAs($this->user)
            ->getJson('/api/v1/sales-orders/next-so-no')
            ->assertOk()
            ->assertJsonPath('data', "SO-{$year}-0001");

        $this->actingAs($this->user)
            ->getJson('/api/v1/sales-orders/order-sources')
            ->assertOk()
            ->assertJsonStructure(['data' => [['value', 'label']]]);
    }

    public function test_available_pieces_lists_only_in_stock_rolls_in_fifo_order(): void
    {
        $product = Product::factory()->create();
        $first   = $this->makeGrnWithPieces($product, [10.0, 20.0], unitPrice: 150);
        // Allocated + draft pieces of the same product must be excluded
        $this->makeGrnWithPieces($product, [30.0], pieceStatus: GrnItemPiece::STATUS_ALLOCATED);
        $this->makeGrnWithPieces($product, [40.0], grnStatus: 'draft', pieceStatus: GrnItemPiece::STATUS_DRAFT);

        $this->actingAs($this->user)
            ->getJson("/api/v1/sales-orders/available-pieces/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.total_weight', 30)
            ->assertJsonPath('data.pieces.0.piece_code', $first['pieces'][0]->piece_code)
            ->assertJsonPath('data.pieces.1.piece_code', $first['pieces'][1]->piece_code)
            ->assertJsonPath('data.pieces.0.grn_unit_price', 150);
    }

    public function test_available_pieces_requires_permission(): void
    {
        $restricted = User::factory()->create(['active_modules' => ['inventory']]);
        $restricted->givePermissionTo('view_sales_orders'); // view only — not create/edit

        $this->actingAs($restricted)
            ->getJson('/api/v1/sales-orders/available-pieces/1')
            ->assertForbidden();
    }

    public function test_store_rejects_manual_quantity_line_when_product_has_rolls_in_stock(): void
    {
        $product = Product::factory()->create();
        $this->makeGrnWithPieces($product, [10.0]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload([
                'items' => [[
                    'product_id' => $product->id,
                    'quantity'   => 5,
                    'unit_price' => 100,
                ]],
            ]))
            ->assertStatus(422);
    }

    public function test_store_allows_manual_quantity_line_for_product_without_rolls(): void
    {
        // validPayload() uses a fresh product with no piece records, but with stock seeded
        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload())
            ->assertCreated();
    }

    public function test_store_rejects_manual_quantity_line_for_product_with_no_stock(): void
    {
        // No ProductLocationStore row at all — the product has never been received
        $product = Product::factory()->create();

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload([
                'items' => [[
                    'product_id' => $product->id,
                    'quantity'   => 5,
                    'unit_price' => 100,
                ]],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.product_id']);
    }

    public function test_store_allows_manual_quantity_line_when_stock_exists_at_any_location(): void
    {
        // Sales orders have no location/store of their own, so stock anywhere counts.
        $product = Product::factory()->create();
        $this->seedStock($product, 25);

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload([
                'items' => [[
                    'product_id' => $product->id,
                    'quantity'   => 5,
                    'unit_price' => 100,
                ]],
            ]))
            ->assertCreated();
    }

    // ── Colour ──────────────────────────────────────────────────────────────

    public function test_scanned_line_derives_colour_from_grn_item(): void
    {
        $product = Product::factory()->create();
        $red     = $this->makeColorAttribute('Red');
        $seed    = $this->makeGrnWithPieces($product, [10.0], attributeId: $red->id);

        $this->actingAs($this->user)
            ->getJson('/api/v1/sales-orders/scan-piece/' . $seed['pieces'][0]->piece_code)
            ->assertOk()
            ->assertJsonPath('data.piece.attribute_id', $red->id)
            ->assertJsonPath('data.piece.color', 'Red');

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload([
                'items' => [[
                    'product_id'  => $product->id,
                    'unit_price'  => 300,
                    'piece_codes' => [$seed['pieces'][0]->piece_code],
                ]],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.items.0.attribute_id', $red->id);
    }

    public function test_store_rejects_mixed_colours_on_one_line(): void
    {
        $product = Product::factory()->create();
        $red     = $this->makeColorAttribute('Red');
        $blue    = $this->makeColorAttribute('Blue');
        $redSeed  = $this->makeGrnWithPieces($product, [10.0], attributeId: $red->id);
        $blueSeed = $this->makeGrnWithPieces($product, [15.0], attributeId: $blue->id);

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', $this->validPayload([
                'items' => [[
                    'product_id'  => $product->id,
                    'unit_price'  => 300,
                    'piece_codes' => [
                        $redSeed['pieces'][0]->piece_code,
                        $blueSeed['pieces'][0]->piece_code,
                    ],
                ]],
            ]))
            ->assertStatus(422);
    }

    public function test_available_pieces_include_colour(): void
    {
        $product = Product::factory()->create();
        $red     = $this->makeColorAttribute('Red');
        $this->makeGrnWithPieces($product, [10.0], attributeId: $red->id);

        $this->actingAs($this->user)
            ->getJson("/api/v1/sales-orders/available-pieces/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.pieces.0.color', 'Red')
            ->assertJsonPath('data.pieces.0.attribute_id', $red->id);
    }

    public function test_product_price_cost_uses_latest_confirmed_grn_only(): void
    {
        // unit_price is the price-list selling price (null here — no price list);
        // cost_price is the latest CONFIRMED GRN cost. Full pricing coverage
        // lives in ProductPricingTest.
        $product = Product::factory()->create();
        $this->makeGrnWithPieces($product, [10], unitPrice: 200);
        // Later draft GRN must be ignored
        $this->makeGrnWithPieces($product, [10], grnStatus: 'draft', pieceStatus: GrnItemPiece::STATUS_DRAFT, unitPrice: 999);

        $this->actingAs($this->user)
            ->getJson("/api/v1/sales-orders/product-price/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.unit_price', null)
            ->assertJsonPath('data.cost_price', 200);
    }
}
