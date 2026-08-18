<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Models\DeliveryOrderPiece;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GoodsReceivedNoteItem;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductLocationStore;
use Modules\Inventory\Models\SalesOrder;
use Modules\Inventory\Models\SalesOrderItem;
use Modules\Inventory\Models\StockTransaction;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DeliveryOrderTest extends TestCase
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
    }

    /**
     * inv_product_location_stores has real FKs to inv_stores/inv_locations,
     * so tests need genuine rows. Returns [storeId, locationId].
     *
     * @return array{0: int, 1: int}
     */
    private function makeStoreAndLocation(): array
    {
        static $seq = 0;
        $seq++;

        $locationId = \Illuminate\Support\Facades\DB::table('inv_locations')->insertGetId([
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

        $storeId = \Illuminate\Support\Facades\DB::table('inv_stores')->insertGetId([
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
     * Seed a GRN with sealed in-stock pieces (mirrors SalesOrderTest helper),
     * with optional store/location/batch and a ProductLocationStore balance.
     *
     * @param array<float> $weights
     * @return array{grn: GoodsReceivedNote, item: GoodsReceivedNoteItem, pieces: array<GrnItemPiece>}
     */
    private function makeGrnWithPieces(
        Product $product,
        array $weights,
        float $unitPrice = 250.0,
        ?int $storeId = null,
        ?int $locationId = null,
    ): array {
        static $seq = 0;
        $seq++;

        $grn = GoodsReceivedNote::create([
            'grn_no'   => sprintf('GRN-2026-%04d', $seq),
            'grn_date' => '2026-07-01',
            'status'   => 'confirmed',
        ]);

        $item = GoodsReceivedNoteItem::create([
            'grn_id'            => $grn->id,
            'product_id'        => $product->id,
            'quantity_received' => array_sum($weights),
            'unit_price'        => $unitPrice,
        ]);

        $pieces = [];
        foreach ($weights as $i => $weight) {
            $pieces[] = GrnItemPiece::create([
                'grn_item_id' => $item->id,
                'grn_id'      => $grn->id,
                'product_id'  => $product->id,
                'store_id'    => $storeId,
                'location_id' => $locationId,
                'piece_no'    => $i + 1,
                'weight'      => $weight,
                'piece_code'  => sprintf('%s-I001-P%03d', $grn->grn_no, $i + 1),
                'status'      => GrnItemPiece::STATUS_IN_STOCK,
            ]);
        }

        // Simulate GRN-posted stock balance
        $pivot = ProductLocationStore::firstOrCreate(
            ['product_id' => $product->id, 'store_id' => $storeId, 'location_id' => $locationId],
            ['current_stock' => 0],
        );
        $pivot->increment('current_stock', array_sum($weights));

        return ['grn' => $grn, 'item' => $item, 'pieces' => $pieces];
    }

    /**
     * Create a confirmed SO with all rolls of the seed allocated on one line.
     *
     * @param array<GrnItemPiece> $pieces
     */
    private function makeConfirmedSoWithRolls(Product $product, array $pieces, float $unitPrice = 1000.0): SalesOrder
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', [
                'order_date'       => '2026-07-09',
                'transaction_date' => '2026-07-08',
                'customer_id'      => $this->customer->id,
                'sales_person_id'  => $this->user->id,
                'customer_type'    => 'Retail',
                'order_source'     => 'walk_in',
                'status'           => 'confirmed',
                'items'            => [[
                    'product_id'  => $product->id,
                    'unit_price'  => $unitPrice,
                    'piece_codes' => array_map(fn (GrnItemPiece $p) => $p->piece_code, $pieces),
                ]],
            ]);
        $response->assertCreated();

        return SalesOrder::findOrFail($response->json('data.id'));
    }

    /** Create a confirmed SO with one manual (typed-qty) line. Product must have no pieces. */
    private function makeConfirmedSoManual(Product $product, float $qty, float $unitPrice = 500.0): SalesOrder
    {
        // A location-less stock pool satisfies the SO-stage "has stock somewhere" check
        // without touching the store/location-specific balance the DO-stage tests below
        // exercise (Delivery Order stock checks are scoped to one exact store + location).
        ProductLocationStore::firstOrCreate(
            ['product_id' => $product->id, 'store_id' => null, 'location_id' => null],
            ['current_stock' => 100000],
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', [
                'order_date'      => '2026-07-09',
                'customer_id'     => $this->customer->id,
                'sales_person_id' => $this->user->id,
                'status'          => 'confirmed',
                'items'           => [[
                    'product_id' => $product->id,
                    'quantity'   => $qty,
                    'unit_price' => $unitPrice,
                ]],
            ]);
        $response->assertCreated();

        return SalesOrder::findOrFail($response->json('data.id'));
    }

    /** @param array<int> $pieceIds */
    private function doPayload(SalesOrder $so, array $items): array
    {
        return [
            'so_id'         => $so->id,
            'delivery_date' => '2026-07-10',
            'items'         => $items,
        ];
    }

    // ── Auth & permissions ──────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/delivery-orders')->assertUnauthorized();
    }

    public function test_user_without_view_permission_is_forbidden(): void
    {
        $restricted = User::factory()->create(['active_modules' => ['inventory']]);

        $this->actingAs($restricted)
            ->getJson('/api/v1/delivery-orders')
            ->assertForbidden();
    }

    // ── Create (draft) ──────────────────────────────────────────────────────

    public function test_creates_partial_do_from_confirmed_so_with_roll_subset(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrnWithPieces($product, [10.0, 20.0, 30.0]);
        $so      = $this->makeConfirmedSoWithRolls($product, $seed['pieces']);
        $soItem  = $so->items()->first();

        $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', $this->doPayload($so, [[
                'so_item_id' => $soItem->id,
                'piece_ids'  => [$seed['pieces'][0]->id, $seed['pieces'][1]->id],
            ]]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.items.0.quantity', 30)
            ->assertJsonPath('data.do_no', 'DO-' . now()->year . '-0001');
    }

    public function test_persists_dispatch_header_fields_and_echoes_so_snapshot(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrnWithPieces($product, [10.0, 20.0]);
        $so      = $this->makeConfirmedSoWithRolls($product, $seed['pieces']);
        $soItem  = $so->items()->first();

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', array_merge(
                $this->doPayload($so, [[
                    'so_item_id' => $soItem->id,
                    'piece_ids'  => [$seed['pieces'][0]->id],
                ]]),
                [
                    'document_date'      => '2026-07-10',
                    'delivery_mode'      => 'Courier',
                    'delivery_vehicle'   => 'Lorry — WP CAB 1234',
                    'responsible_person' => 'John Driver',
                ],
            ))
            ->assertCreated()
            ->assertJsonPath('data.document_date', '2026-07-10')
            ->assertJsonPath('data.delivery_mode', 'Courier')
            ->assertJsonPath('data.delivery_vehicle', 'Lorry — WP CAB 1234')
            ->assertJsonPath('data.responsible_person', 'John Driver')
            // Read-only SO snapshot surfaced on the resource
            ->assertJsonPath('data.sales_order.customer_type', 'Retail')
            ->assertJsonPath('data.sales_order.sales_person', $this->user->name);

        $this->assertDatabaseHas('inv_delivery_orders', [
            'id'                 => $response->json('data.id'),
            'delivery_mode'      => 'Courier',
            'delivery_vehicle'   => 'Lorry — WP CAB 1234',
            'responsible_person' => 'John Driver',
        ]);
    }

    public function test_from_so_recall_exposes_readonly_header_snapshot(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrnWithPieces($product, [10.0]);
        $so      = $this->makeConfirmedSoWithRolls($product, $seed['pieces']);

        $this->actingAs($this->user)
            ->getJson("/api/v1/delivery-orders/from-so/{$so->id}")
            ->assertOk()
            ->assertJsonPath('data.sales_order.customer_type', 'Retail')
            ->assertJsonPath('data.sales_order.sales_person', $this->user->name)
            ->assertJsonPath('data.sales_order.customer.name', 'Test Customer');
    }

    public function test_rejects_do_from_draft_so(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrnWithPieces($product, [10.0]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/sales-orders', [
            'order_date'      => '2026-07-09',
            'customer_id'     => $this->customer->id,
            'sales_person_id' => $this->user->id,
            'status'          => 'draft',
            'items'           => [[
                'product_id'  => $product->id,
                'unit_price'  => 1000,
                'piece_codes' => [$seed['pieces'][0]->piece_code],
            ]],
        ]);
        $so = SalesOrder::findOrFail($response->json('data.id'));

        $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', $this->doPayload($so, [[
                'so_item_id' => $so->items()->first()->id,
                'piece_ids'  => [$seed['pieces'][0]->id],
            ]]))
            ->assertStatus(422);
    }

    public function test_rejects_roll_not_allocated_to_the_so_line(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrnWithPieces($product, [10.0, 20.0]);
        // Only the first roll goes on the SO
        $so = $this->makeConfirmedSoWithRolls($product, [$seed['pieces'][0]]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', $this->doPayload($so, [[
                'so_item_id' => $so->items()->first()->id,
                'piece_ids'  => [$seed['pieces'][1]->id], // never allocated
            ]]))
            ->assertStatus(422);
    }

    public function test_rejects_roll_already_on_another_do(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrnWithPieces($product, [10.0]);
        $so      = $this->makeConfirmedSoWithRolls($product, $seed['pieces']);
        $soItem  = $so->items()->first();

        $payload = $this->doPayload($so, [[
            'so_item_id' => $soItem->id,
            'piece_ids'  => [$seed['pieces'][0]->id],
        ]]);

        $this->actingAs($this->user)->postJson('/api/v1/delivery-orders', $payload)->assertCreated();
        $this->actingAs($this->user)->postJson('/api/v1/delivery-orders', $payload)->assertStatus(422);
    }

    public function test_rejects_manual_quantity_exceeding_remainder(): void
    {
        $product = Product::factory()->create();
        $so      = $this->makeConfirmedSoManual($product, 50);
        [$storeId, $locationId] = $this->makeStoreAndLocation();

        $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', array_merge(
                $this->doPayload($so, [[
                    'so_item_id' => $so->items()->first()->id,
                    'quantity'   => 60,
                ]]),
                ['store_id' => $storeId, 'location_id' => $locationId],
            ))
            ->assertStatus(422);
    }

    public function test_rejects_manual_overshoot_across_two_draft_dos(): void
    {
        $product = Product::factory()->create();
        $so      = $this->makeConfirmedSoManual($product, 50);
        $soItem  = $so->items()->first();
        [$storeId, $locationId] = $this->makeStoreAndLocation();

        $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', array_merge(
                $this->doPayload($so, [['so_item_id' => $soItem->id, 'quantity' => 30]]),
                ['store_id' => $storeId, 'location_id' => $locationId],
            ))->assertCreated();

        $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', array_merge(
                $this->doPayload($so, [['so_item_id' => $soItem->id, 'quantity' => 30]]),
                ['store_id' => $storeId, 'location_id' => $locationId],
            ))->assertStatus(422);
    }

    public function test_rejects_manual_line_without_store_and_location(): void
    {
        $product = Product::factory()->create();
        $so      = $this->makeConfirmedSoManual($product, 50);

        $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', $this->doPayload($so, [[
                'so_item_id' => $so->items()->first()->id,
                'quantity'   => 10,
            ]]))
            ->assertStatus(422);
    }

    // ── from-so recall ──────────────────────────────────────────────────────

    public function test_from_so_lists_available_rolls_and_excludes_taken_ones(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrnWithPieces($product, [10.0, 20.0]);
        $so      = $this->makeConfirmedSoWithRolls($product, $seed['pieces']);
        $soItem  = $so->items()->first();

        // Put the first roll on a draft DO
        $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', $this->doPayload($so, [[
                'so_item_id' => $soItem->id,
                'piece_ids'  => [$seed['pieces'][0]->id],
            ]]))->assertCreated();

        $this->actingAs($this->user)
            ->getJson("/api/v1/delivery-orders/from-so/{$so->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items.0.available_pieces')
            ->assertJsonPath('data.items.0.available_pieces.0.piece_id', $seed['pieces'][1]->id);
    }

    /**
     * The DO form's roll cards are the tick target — the picker identifies the physical roll
     * from them alone. So the recall payload has to carry where the roll is, where it came
     * from, what it holds, and the slice this sale takes off it (less, on a cut roll), plus
     * the two UOM symbols the figures are quoted in.
     */
    public function test_from_so_recall_carries_roll_identity_uom_and_cut_slice(): void
    {
        $product = Product::factory()->create();
        [$storeId, $locationId] = $this->makeStoreAndLocation();
        $seed = $this->makeGrnWithPieces($product, [10.0, 20.0], 250.0, $storeId, $locationId);

        // Sell 25 of the 30 on the rack — the rolls cannot all ship whole, so one is cut.
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', [
                'order_date'      => '2026-07-09',
                'customer_id'     => $this->customer->id,
                'sales_person_id' => $this->user->id,
                'status'          => 'confirmed',
                'items'           => [[
                    'product_id'  => $product->id,
                    'quantity'    => 25,
                    'unit_price'  => 1000.0,
                    'piece_codes' => array_map(fn (GrnItemPiece $p) => $p->piece_code, $seed['pieces']),
                ]],
            ])->assertCreated();

        $soId = $response->json('data.id');

        $payload = $this->actingAs($this->user)
            ->getJson("/api/v1/delivery-orders/from-so/{$soId}")
            ->assertOk()
            ->json('data.items.0');

        // Both UOM symbols travel with the line, along with the factor that bridges them.
        $this->assertNotNull($payload['unit']['symbol'] ?? null);
        $this->assertNotNull($payload['base_unit']['symbol'] ?? null);
        $this->assertGreaterThan(0, $payload['conversion_factor']);

        $pieces = collect($payload['available_pieces']);
        $this->assertCount(2, $pieces);

        foreach ($pieces as $piece) {
            $this->assertStringStartsWith('Test Store', $piece['store']);
            $this->assertStringStartsWith('Test Location', $piece['location']);
            $this->assertStringStartsWith('GRN-2026-', $piece['grn_no']);
            $this->assertArrayHasKey('batch_no', $piece);
            $this->assertGreaterThan(0, $piece['weight']);
        }

        // 25 off 30 leaves exactly one roll cut, and the shipped slices still sum to the order.
        $this->assertSame(1, $pieces->where('is_cut', true)->count());
        $this->assertEqualsWithDelta(25.0, $pieces->sum('taken_quantity'), 0.0001);

        $cut = $pieces->firstWhere('is_cut', true);
        $this->assertLessThan($cut['weight'], $cut['taken_quantity']);
    }

    // ── Confirm: the outbound stock posting ─────────────────────────────────

    public function test_confirm_posts_sales_delivery_ledger_rows_and_decrements_stock(): void
    {
        $product = Product::factory()->create();
        [$storeId, $locationId] = $this->makeStoreAndLocation();
        $seed    = $this->makeGrnWithPieces($product, [10.0, 20.0], storeId: $storeId, locationId: $locationId);
        $so      = $this->makeConfirmedSoWithRolls($product, $seed['pieces'], unitPrice: 1200);
        $soItem  = $so->items()->first();

        $doId = $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', $this->doPayload($so, [[
                'so_item_id' => $soItem->id,
                'piece_ids'  => array_map(fn ($p) => $p->id, $seed['pieces']),
            ]]))->json('data.id');

        $this->actingAs($this->user)
            ->patchJson("/api/v1/delivery-orders/{$doId}/status", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        // One grouped ledger row (same item, store, location, no batch)
        $txns = StockTransaction::where('reference_type', 'sales_delivery')
            ->where('reference_id', $doId)
            ->get();
        $this->assertCount(1, $txns);
        $this->assertEquals(30.0, (float) $txns->first()->qty_out);
        $this->assertEquals(0.0, (float) $txns->first()->qty_in);
        $this->assertEquals(1200.0, (float) $txns->first()->unit_price); // SO selling price
        $this->assertSame($storeId, $txns->first()->store_id);
        $this->assertSame($locationId, $txns->first()->location_id);

        // Pivot decremented from 30 to 0
        $pivot = ProductLocationStore::where('product_id', $product->id)
            ->where('store_id', $storeId)->where('location_id', $locationId)->first();
        $this->assertEquals(0.0, (float) $pivot->current_stock);

        // Pieces delivered, GRN inbound link intact, outbound txn stamped on DO piece rows
        foreach ($seed['pieces'] as $piece) {
            $this->assertSame(GrnItemPiece::STATUS_DELIVERED, $piece->fresh()->status);
        }
        $this->assertSame(
            2,
            DeliveryOrderPiece::where('do_id', $doId)
                ->where('stock_transaction_id', $txns->first()->id)
                ->count(),
        );
    }

    public function test_insufficient_stock_blocks_manual_confirm_when_minus_not_allowed(): void
    {
        $product = Product::factory()->create(['allow_minus' => false]);
        $so      = $this->makeConfirmedSoManual($product, 50);
        [$storeId, $locationId] = $this->makeStoreAndLocation();

        // No ProductLocationStore balance seeded — confirm must fail
        $doId = $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', array_merge(
                $this->doPayload($so, [['so_item_id' => $so->items()->first()->id, 'quantity' => 50]]),
                ['store_id' => $storeId, 'location_id' => $locationId],
            ))->json('data.id');

        $this->actingAs($this->user)
            ->patchJson("/api/v1/delivery-orders/{$doId}/status", ['status' => 'confirmed'])
            ->assertStatus(422);

        $this->assertSame(0, StockTransaction::where('reference_type', 'sales_delivery')->count());
    }

    public function test_allow_minus_product_can_go_negative(): void
    {
        $product = Product::factory()->create(['allow_minus' => true]);
        $so      = $this->makeConfirmedSoManual($product, 50);
        [$storeId, $locationId] = $this->makeStoreAndLocation();

        $doId = $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', array_merge(
                $this->doPayload($so, [['so_item_id' => $so->items()->first()->id, 'quantity' => 50]]),
                ['store_id' => $storeId, 'location_id' => $locationId],
            ))->json('data.id');

        $this->actingAs($this->user)
            ->patchJson("/api/v1/delivery-orders/{$doId}/status", ['status' => 'confirmed'])
            ->assertOk();

        $pivot = ProductLocationStore::where('product_id', $product->id)
            ->where('store_id', $storeId)->where('location_id', $locationId)->first();
        $this->assertEquals(-50.0, (float) $pivot->current_stock);
    }

    public function test_partial_delivery_keeps_so_confirmed_and_full_delivery_completes_it(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrnWithPieces($product, [10.0, 20.0]);
        $so      = $this->makeConfirmedSoWithRolls($product, $seed['pieces']);
        $soItem  = $so->items()->first();

        // First DO — one roll
        $do1 = $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', $this->doPayload($so, [[
                'so_item_id' => $soItem->id,
                'piece_ids'  => [$seed['pieces'][0]->id],
            ]]))->json('data.id');
        $this->actingAs($this->user)
            ->patchJson("/api/v1/delivery-orders/{$do1}/status", ['status' => 'confirmed'])
            ->assertOk();

        $this->assertSame('confirmed', $so->fresh()->status->value);
        $this->assertEquals(10.0, (float) $soItem->fresh()->quantity_delivered);

        // Second DO — the remainder
        $do2 = $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', $this->doPayload($so, [[
                'so_item_id' => $soItem->id,
                'piece_ids'  => [$seed['pieces'][1]->id],
            ]]))->json('data.id');
        $this->actingAs($this->user)
            ->patchJson("/api/v1/delivery-orders/{$do2}/status", ['status' => 'confirmed'])
            ->assertOk();

        $this->assertSame('completed', $so->fresh()->status->value);
    }

    // ── Immutability & cancel ───────────────────────────────────────────────

    public function test_confirmed_do_cannot_be_edited_cancelled_or_deleted(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrnWithPieces($product, [10.0]);
        $so      = $this->makeConfirmedSoWithRolls($product, $seed['pieces']);
        $soItem  = $so->items()->first();

        $payload = $this->doPayload($so, [[
            'so_item_id' => $soItem->id,
            'piece_ids'  => [$seed['pieces'][0]->id],
        ]]);
        $doId = $this->actingAs($this->user)->postJson('/api/v1/delivery-orders', $payload)->json('data.id');
        $this->actingAs($this->user)->patchJson("/api/v1/delivery-orders/{$doId}/status", ['status' => 'confirmed'])->assertOk();

        $this->actingAs($this->user)->putJson("/api/v1/delivery-orders/{$doId}", $payload)->assertStatus(422);
        $this->actingAs($this->user)->patchJson("/api/v1/delivery-orders/{$doId}/status", ['status' => 'cancelled'])->assertStatus(422);
        $this->actingAs($this->user)->deleteJson("/api/v1/delivery-orders/{$doId}")->assertStatus(422);
    }

    public function test_draft_cancel_releases_piece_rows_and_pieces_stay_allocated(): void
    {
        $product = Product::factory()->create();
        $seed    = $this->makeGrnWithPieces($product, [10.0]);
        $so      = $this->makeConfirmedSoWithRolls($product, $seed['pieces']);

        $doId = $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', $this->doPayload($so, [[
                'so_item_id' => $so->items()->first()->id,
                'piece_ids'  => [$seed['pieces'][0]->id],
            ]]))->json('data.id');

        $this->actingAs($this->user)
            ->patchJson("/api/v1/delivery-orders/{$doId}/status", ['status' => 'cancelled'])
            ->assertOk();

        $this->assertSame(0, DeliveryOrderPiece::where('do_id', $doId)->count());
        $this->assertSame(GrnItemPiece::STATUS_ALLOCATED, $seed['pieces'][0]->fresh()->status);
    }

    public function test_next_do_no_preview(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/delivery-orders/next-do-no')
            ->assertOk()
            ->assertJsonPath('data', 'DO-' . now()->year . '-0001');
    }

    // ── Reports integration ─────────────────────────────────────────────────

    public function test_bin_card_resolves_do_number_for_sales_delivery_rows(): void
    {
        Permission::create(['name' => 'view_reports', 'guard_name' => 'web']);
        $this->user->givePermissionTo('view_reports');

        $product = Product::factory()->create();
        [$storeId, $locationId] = $this->makeStoreAndLocation();
        $seed    = $this->makeGrnWithPieces($product, [10.0], storeId: $storeId, locationId: $locationId);
        $so      = $this->makeConfirmedSoWithRolls($product, $seed['pieces']);

        $doId = $this->actingAs($this->user)
            ->postJson('/api/v1/delivery-orders', $this->doPayload($so, [[
                'so_item_id' => $so->items()->first()->id,
                'piece_ids'  => [$seed['pieces'][0]->id],
            ]]))->json('data.id');
        $doNo = \Modules\Inventory\Models\DeliveryOrder::find($doId)->do_no;

        $this->actingAs($this->user)
            ->patchJson("/api/v1/delivery-orders/{$doId}/status", ['status' => 'confirmed'])
            ->assertOk();

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/reports/inventory/bin-card?product_id={$product->id}")
            ->assertOk();

        $rows = collect($response->json('rows'));
        $this->assertTrue(
            $rows->contains(fn ($row) => ($row['document_no'] ?? null) === $doNo),
            'Bin card should show the DO number on the sales_delivery row. Rows: ' . json_encode($rows),
        );
    }
}
