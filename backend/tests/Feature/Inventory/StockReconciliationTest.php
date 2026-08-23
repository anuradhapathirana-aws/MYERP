<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\ProductServiceType;
use Modules\Inventory\Enums\PurchaseOrderStatus;
use Modules\Inventory\Models\Attribute;
use Modules\Inventory\Models\AttributeType;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GoodsReceivedNoteItem;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductLocationStore;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\PurchaseOrderItem;
use Modules\Inventory\Models\SupplierMaster;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A confirmed GRN (with a confirmed Costing on top of it) can record the wrong roll
 * weight, and neither document can be reopened by design. StockReconciliation is the
 * separate, traceable correction: it is drafted, submitted, then approved by any user
 * holding approve_stock_reconciliations (admin/super_admin — the creator may approve
 * their own) before it touches the ledger, the denormalized balance, and the roll's
 * own weight — all together, inside one transaction — and approval always recomputes
 * against the LIVE balance/roll weight, never the stale snapshot taken at draft time.
 */
class StockReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;
    private User $checker;
    private int $storeId;
    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ([
            'view_stock_reconciliations', 'create_stock_reconciliations',
            'edit_stock_reconciliations', 'delete_stock_reconciliations',
            'approve_stock_reconciliations',
        ] as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->maker   = User::factory()->create(['active_modules' => ['inventory']]);
        $this->checker = User::factory()->create(['active_modules' => ['inventory']]);

        foreach ([$this->maker, $this->checker] as $user) {
            $user->givePermissionTo([
                'view_stock_reconciliations', 'create_stock_reconciliations',
                'edit_stock_reconciliations', 'delete_stock_reconciliations',
                'approve_stock_reconciliations',
            ]);
        }

        [$this->storeId, $this->locationId] = $this->makeStoreAndLocation();
    }

    /** @return array{0: int, 1: int} */
    private function makeStoreAndLocation(): array
    {
        $locationId = DB::table('inv_locations')->insertGetId([
            'company_id'             => 1,
            'industry_id'            => 1,
            'location_code'          => 'LOC-1',
            'location_name'          => 'Test Location',
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
            'store_code'    => 'STR-1',
            'store_name'    => 'Test Store',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return [$storeId, $locationId];
    }

    private function makeColourAttribute(string $name): Attribute
    {
        $category = Category::create(['product_service_type' => ProductServiceType::Product, 'category_name' => 'Test Category ' . $name]);
        $type = AttributeType::create([
            'category_id'          => $category->id,
            'product_service_type' => ProductServiceType::Product,
            'attribute_type_name'  => 'Colour',
        ]);

        return Attribute::create([
            'attribute_type_id' => $type->id,
            'attribute_name'    => $name,
        ]);
    }

    /**
     * A confirmed GRN (optionally PO-linked) with one roll already in stock, whose
     * weight is wrong by construction — this is the exact scenario the feature exists
     * to fix.
     *
     * @return array{product: Product, grn: GoodsReceivedNote, item: GoodsReceivedNoteItem, roll: GrnItemPiece, poItem: ?PurchaseOrderItem}
     */
    private function wrongRollScenario(float $recordedWeight, bool $withPo = false, ?int $attributeId = null): array
    {
        static $seq = 0;
        $seq++;

        $product = Product::factory()->create();

        $poItem = null;
        $poId   = null;

        if ($withPo) {
            $supplier = SupplierMaster::create([
                'supplier_code' => sprintf('SUP-SR-%04d', $seq),
                'supplier_name' => 'Stock Reconciliation Test Supplier',
            ]);
            $po = PurchaseOrder::create([
                'po_no'       => sprintf('PO-SR-%04d', $seq),
                'supplier_id' => $supplier->id,
                'store_id'    => $this->storeId,
                'location_id' => $this->locationId,
                'order_date'  => '2026-08-01',
                'status'      => PurchaseOrderStatus::Confirmed,
            ]);
            $poItem = PurchaseOrderItem::create([
                'po_id'             => $po->id,
                'product_id'        => $product->id,
                'quantity_ordered'  => $recordedWeight,
                'quantity_received' => $recordedWeight,
                'unit_price'        => 100,
            ]);
            $poId = $po->id;
        }

        $grn = GoodsReceivedNote::create([
            'grn_no'      => sprintf('GRN-SR-%04d', $seq),
            'grn_date'    => '2026-08-01',
            'status'      => 'confirmed',
            'store_id'    => $this->storeId,
            'location_id' => $this->locationId,
            'po_id'       => $poId,
        ]);

        $item = GoodsReceivedNoteItem::create([
            'grn_id'            => $grn->id,
            'po_item_id'        => $poItem?->id,
            'product_id'        => $product->id,
            'attribute_id'      => $attributeId,
            'quantity_received' => $recordedWeight,
            'base_quantity'     => $recordedWeight,
            'conversion_factor' => 1,
            'unit_price'        => 100,
        ]);

        $roll = GrnItemPiece::create([
            'grn_item_id' => $item->id,
            'grn_id'      => $grn->id,
            'product_id'  => $product->id,
            'store_id'    => $this->storeId,
            'location_id' => $this->locationId,
            'piece_no'    => 1,
            'roll_no'     => 'R-1',
            'weight'      => $recordedWeight,
            'piece_code'  => sprintf('%s-I001-P001', $grn->grn_no),
            'status'      => GrnItemPiece::STATUS_IN_STOCK,
        ]);

        ProductLocationStore::create([
            'product_id'    => $product->id,
            'store_id'      => $this->storeId,
            'location_id'   => $this->locationId,
            'current_stock' => $recordedWeight,
        ]);

        return ['product' => $product, 'grn' => $grn, 'item' => $item, 'roll' => $roll, 'poItem' => $poItem];
    }

    public function test_only_draft_reconciliations_can_be_edited_or_deleted(): void
    {
        ['product' => $product, 'roll' => $roll] = $this->wrongRollScenario(100.0);

        $create = $this->actingAs($this->maker)->postJson('/api/v1/stock-reconciliations', [
            'product_id'  => $product->id,
            'store_id'    => $this->storeId,
            'location_id' => $this->locationId,
            'reason'      => 'Roll weighed wrong at receiving',
            'pieces'      => [['grn_item_piece_id' => $roll->id, 'new_weight' => 90.0]],
        ])->assertStatus(201);

        $id = $create->json('data.id');

        // Draft can be edited freely
        $this->actingAs($this->maker)->putJson("/api/v1/stock-reconciliations/{$id}", [
            'product_id'  => $product->id,
            'store_id'    => $this->storeId,
            'location_id' => $this->locationId,
            'reason'      => 'Roll weighed wrong at receiving — corrected reason',
            'pieces'      => [['grn_item_piece_id' => $roll->id, 'new_weight' => 88.0]],
        ])->assertOk();

        $this->actingAs($this->maker)->postJson("/api/v1/stock-reconciliations/{$id}/submit")->assertOk();

        // No longer editable/deletable once submitted
        $this->actingAs($this->maker)->putJson("/api/v1/stock-reconciliations/{$id}", [
            'product_id'  => $product->id,
            'store_id'    => $this->storeId,
            'location_id' => $this->locationId,
            'reason'      => 'try to edit after submit',
            'pieces'      => [['grn_item_piece_id' => $roll->id, 'new_weight' => 88.0]],
        ])->assertStatus(422);

        $this->actingAs($this->maker)->deleteJson("/api/v1/stock-reconciliations/{$id}")->assertStatus(422);
    }

    public function test_approving_a_roll_correction_moves_ledger_balance_and_roll_weight_together_and_leaves_the_grn_untouched(): void
    {
        ['product' => $product, 'grn' => $grn, 'item' => $item, 'roll' => $roll] = $this->wrongRollScenario(100.0);

        $originalGrnQty = (float) $item->quantity_received;

        $id = $this->actingAs($this->maker)->postJson('/api/v1/stock-reconciliations', [
            'product_id'  => $product->id,
            'store_id'    => $this->storeId,
            'location_id' => $this->locationId,
            'reason'      => 'Roll weighed wrong at receiving',
            'pieces'      => [['grn_item_piece_id' => $roll->id, 'new_weight' => 90.0]],
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($this->maker)->postJson("/api/v1/stock-reconciliations/{$id}/submit")->assertOk();

        // Any user holding approve_stock_reconciliations may approve — including the
        // one who created it; approval is gated by permission (admin/super_admin), not
        // by being a different person.
        $approve = $this->actingAs($this->maker)->postJson("/api/v1/stock-reconciliations/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertEqualsWithDelta(-10.0, (float) $approve->json('data.variance_qty_base'), 0.0001);

        // Roll weight corrected
        $this->assertEquals(90.0, (float) $roll->fresh()->weight);

        // Denormalized balance moved by the same delta
        $pivot = ProductLocationStore::where('product_id', $product->id)
            ->where('store_id', $this->storeId)->where('location_id', $this->locationId)->first();
        $this->assertEquals(90.0, (float) $pivot->current_stock);

        // A stock_adjustment ledger row exists for the delta
        $this->assertDatabaseHas('inv_stock_transactions', [
            'reference_type' => 'stock_adjustment',
            'reference_id'   => $id,
            'product_id'     => $product->id,
            'qty_out'        => 10.0,
        ]);

        // The original GRN document is untouched
        $this->assertEquals($originalGrnQty, (float) $item->fresh()->quantity_received);
        $this->assertEquals('confirmed', $grn->fresh()->status->value);
    }

    public function test_approve_recomputes_against_the_live_balance_not_the_stale_draft_snapshot(): void
    {
        ['product' => $product] = $this->wrongRollScenario(100.0);

        $id = $this->actingAs($this->maker)->postJson('/api/v1/stock-reconciliations', [
            'product_id'  => $product->id,
            'store_id'    => $this->storeId,
            'location_id' => $this->locationId,
            'reason'      => 'Physical count says 90, not 100',
            'counted_qty' => 90.0,
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($this->maker)->postJson("/api/v1/stock-reconciliations/{$id}/submit")->assertOk();

        // Stock moves for an unrelated reason while this is pending approval
        // (e.g. a new GRN receipt of 20 more units of the same product/store/location).
        ProductLocationStore::where('product_id', $product->id)
            ->where('store_id', $this->storeId)->where('location_id', $this->locationId)
            ->increment('current_stock', 20);

        $this->actingAs($this->checker)->postJson("/api/v1/stock-reconciliations/{$id}/approve")->assertOk();

        // The counted target (90) is authoritative — the ending balance lands exactly
        // on it regardless of the unrelated +20 that happened while this was pending.
        $pivot = ProductLocationStore::where('product_id', $product->id)
            ->where('store_id', $this->storeId)->where('location_id', $this->locationId)->first();
        $this->assertEquals(90.0, (float) $pivot->current_stock);
    }

    public function test_approve_is_blocked_when_the_targeted_roll_is_no_longer_in_stock(): void
    {
        ['product' => $product, 'roll' => $roll] = $this->wrongRollScenario(100.0);

        $id = $this->actingAs($this->maker)->postJson('/api/v1/stock-reconciliations', [
            'product_id'  => $product->id,
            'store_id'    => $this->storeId,
            'location_id' => $this->locationId,
            'reason'      => 'Roll weighed wrong at receiving',
            'pieces'      => [['grn_item_piece_id' => $roll->id, 'new_weight' => 90.0]],
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($this->maker)->postJson("/api/v1/stock-reconciliations/{$id}/submit")->assertOk();

        // The roll gets allocated to a sales order while this is pending approval
        $roll->update(['status' => GrnItemPiece::STATUS_ALLOCATED]);

        $this->actingAs($this->checker)->postJson("/api/v1/stock-reconciliations/{$id}/approve")->assertStatus(422);

        // Nothing posted
        $this->assertDatabaseMissing('inv_stock_transactions', [
            'reference_type' => 'stock_adjustment',
            'reference_id'   => $id,
        ]);
    }

    public function test_reject_requires_a_reason(): void
    {
        ['product' => $product, 'roll' => $roll] = $this->wrongRollScenario(100.0);

        $id = $this->actingAs($this->maker)->postJson('/api/v1/stock-reconciliations', [
            'product_id'  => $product->id,
            'store_id'    => $this->storeId,
            'location_id' => $this->locationId,
            'reason'      => 'Roll weighed wrong at receiving',
            'pieces'      => [['grn_item_piece_id' => $roll->id, 'new_weight' => 90.0]],
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($this->maker)->postJson("/api/v1/stock-reconciliations/{$id}/submit")->assertOk();

        $this->actingAs($this->checker)->postJson("/api/v1/stock-reconciliations/{$id}/reject", [])
            ->assertStatus(422);

        // Approval is gated by permission, not by being a different person from the
        // creator — the same user who raised it may also reject it.
        $this->actingAs($this->maker)->postJson("/api/v1/stock-reconciliations/{$id}/reject", ['reason' => 'Recount needed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Recount needed');
    }

    public function test_also_adjust_po_corrects_the_linked_purchase_order_received_quantity(): void
    {
        ['product' => $product, 'grn' => $grn, 'roll' => $roll, 'poItem' => $poItem] =
            $this->wrongRollScenario(100.0, withPo: true);

        $id = $this->actingAs($this->maker)->postJson('/api/v1/stock-reconciliations', [
            'product_id'     => $product->id,
            'store_id'       => $this->storeId,
            'location_id'    => $this->locationId,
            'reason'         => 'Roll weighed wrong at receiving',
            'source_type'    => 'grn',
            'source_id'      => $grn->id,
            'also_adjust_po' => true,
            'pieces'         => [['grn_item_piece_id' => $roll->id, 'new_weight' => 90.0]],
        ])->assertStatus(201)->json('data.id');

        $this->actingAs($this->maker)->postJson("/api/v1/stock-reconciliations/{$id}/submit")->assertOk();
        $this->actingAs($this->checker)->postJson("/api/v1/stock-reconciliations/{$id}/approve")->assertOk();

        // Ordered 100, corrected received down to 90 — 10 units are now outstanding again.
        $this->assertEquals(90.0, (float) $poItem->fresh()->quantity_received);
        $this->assertEquals(PurchaseOrderStatus::PartiallyReceived, $poItem->purchaseOrder->fresh()->status);
    }

    public function test_selecting_a_roll_derives_its_colour_and_writes_it_to_the_ledger(): void
    {
        $red = $this->makeColourAttribute('Red');
        ['product' => $product, 'roll' => $roll] = $this->wrongRollScenario(100.0, attributeId: $red->id);

        $id = $this->actingAs($this->maker)->postJson('/api/v1/stock-reconciliations', [
            'product_id'  => $product->id,
            'store_id'    => $this->storeId,
            'location_id' => $this->locationId,
            'reason'      => 'Roll weighed wrong at receiving',
            'pieces'      => [['grn_item_piece_id' => $roll->id, 'new_weight' => 90.0]],
        ])->assertStatus(201)
            ->assertJsonPath('data.attribute.name', 'Red')
            ->json('data.id');

        $this->actingAs($this->maker)->postJson("/api/v1/stock-reconciliations/{$id}/submit")->assertOk();
        $this->actingAs($this->checker)->postJson("/api/v1/stock-reconciliations/{$id}/approve")->assertOk();

        $this->assertDatabaseHas('inv_stock_transactions', [
            'reference_type' => 'stock_adjustment',
            'reference_id'   => $id,
            'attribute_id'   => $red->id,
        ]);
    }

    public function test_selecting_rolls_of_more_than_one_colour_is_rejected(): void
    {
        $red  = $this->makeColourAttribute('Red');
        $blue = $this->makeColourAttribute('Blue');

        ['product' => $redProduct, 'roll' => $redRoll] = $this->wrongRollScenario(100.0, attributeId: $red->id);

        // A second GRN line/roll for the SAME product, but Blue.
        $blueGrn = GoodsReceivedNote::create([
            'grn_no'      => 'GRN-SR-BLUE',
            'grn_date'    => '2026-08-01',
            'status'      => 'confirmed',
            'store_id'    => $this->storeId,
            'location_id' => $this->locationId,
        ]);
        $blueItem = GoodsReceivedNoteItem::create([
            'grn_id'            => $blueGrn->id,
            'product_id'        => $redProduct->id,
            'attribute_id'      => $blue->id,
            'quantity_received' => 50,
            'base_quantity'     => 50,
            'conversion_factor' => 1,
            'unit_price'        => 100,
        ]);
        $blueRoll = GrnItemPiece::create([
            'grn_item_id' => $blueItem->id,
            'grn_id'      => $blueGrn->id,
            'product_id'  => $redProduct->id,
            'store_id'    => $this->storeId,
            'location_id' => $this->locationId,
            'piece_no'    => 1,
            'roll_no'     => 'R-BLUE-1',
            'weight'      => 50,
            'piece_code'  => 'GRN-SR-BLUE-I001-P001',
            'status'      => GrnItemPiece::STATUS_IN_STOCK,
        ]);

        $this->actingAs($this->maker)->postJson('/api/v1/stock-reconciliations', [
            'product_id'  => $redProduct->id,
            'store_id'    => $this->storeId,
            'location_id' => $this->locationId,
            'reason'      => 'Mixed colours on purpose',
            'pieces'      => [
                ['grn_item_piece_id' => $redRoll->id, 'new_weight' => 90.0],
                ['grn_item_piece_id' => $blueRoll->id, 'new_weight' => 45.0],
            ],
        ])->assertStatus(422);
    }
}
