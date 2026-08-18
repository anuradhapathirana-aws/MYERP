<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Costing;
use Modules\Inventory\Models\CostingExpenseType;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GoodsReceivedNoteItem;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\SalesChannel;
use Modules\Inventory\Models\SupplierMaster;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Costing redesign: per-product cost build-up, every amount per BASE unit.
 *   before_tax = fob/cif (GRN price) + Σ expenses + margin
 *                + sscl% × (fob/cif + expenses + margin)   [default 1.25%]
 *   after_tax  = before_tax + vat%              (= selling_price)
 * Confirm keeps the one-GRN-per-confirmed-costing lock and mirrors
 * landed/selling onto the product price list.
 */
class CostingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private SupplierMaster $supplier;
    private CostingExpenseType $freight;
    private CostingExpenseType $duty;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['view_costings', 'create_costings', 'edit_costings', 'confirm_costings', 'delete_costings'] as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo(['view_costings', 'create_costings', 'edit_costings', 'confirm_costings', 'delete_costings']);

        $this->supplier = SupplierMaster::create([
            'supplier_code' => 'SUP-0001',
            'supplier_name' => 'Costing Supplier',
        ]);

        $this->freight = CostingExpenseType::create([
            'name'         => 'Freight Charges',
            'costing_type' => 'fob',
            'is_active'    => true,
            'sort_order'   => 1,
        ]);

        $this->duty = CostingExpenseType::create([
            'name'         => 'Custom Duty',
            'costing_type' => 'fob',
            'is_active'    => true,
            'sort_order'   => 2,
        ]);
    }

    /** Confirmed GRN with a single item line. */
    private function makeGrn(Product $product, float $qty, float $unitPrice): array
    {
        static $seq = 0;
        $seq++;

        $grn = GoodsReceivedNote::create([
            'grn_no'      => sprintf('GRN-CT-%04d', $seq),
            'grn_date'    => '2026-07-01',
            'supplier_id' => $this->supplier->id,
            'status'      => 'confirmed',
        ]);

        $item = GoodsReceivedNoteItem::create([
            'grn_id'            => $grn->id,
            'product_id'        => $product->id,
            'quantity_received' => $qty,
            'unit_price'        => $unitPrice,
        ]);

        return ['grn' => $grn, 'item' => $item];
    }

    /** @param array<string, mixed> $overrides */
    private function validPayload(array $grnIds, array $overrides = []): array
    {
        return array_merge([
            'supplier_id'      => $this->supplier->id,
            'costing_type'     => 'fob',
            'grn_ids'          => $grnIds,
            'transaction_date' => '2026-07-10',
            'apply_sscl'       => false,
            'sscl_pct'         => 1.25,
            'apply_vat'        => false,
            'vat_pct'          => 18,
            'items'            => [],
        ], $overrides);
    }

    private function givePriceList(Product $product, ?float $selling, ?float $cost = null): int
    {
        $channel = SalesChannel::firstOrCreate(['sales_channel_name' => 'Default']);

        return (int) DB::table('inv_product_sales_channels')->insertGetId([
            'product_id'       => $product->id,
            'sales_channel_id' => $channel->id,
            'cost_price'       => $cost,
            'selling_price'    => $selling,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    // ── Auth ────────────────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/costings')->assertUnauthorized();
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $restricted = User::factory()->create(['active_modules' => ['inventory']]);

        $this->actingAs($restricted)->getJson('/api/v1/costings')->assertForbidden();
    }

    // ── Per-product build-up math ───────────────────────────────────────────

    public function test_create_builds_per_product_prices_from_typed_inputs(): void
    {
        $product = Product::factory()->create();
        $grn = $this->makeGrn($product, qty: 10, unitPrice: 1000);

        // fob 1000 + expenses (150+50) + margin 60 = 1260
        // sscl 1.25% × 1260 = 15.75, rounded UP to 16 → before_tax = 1276
        // vat 18% × 1276 = 229.68 → after_tax = 1505.68
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/costings', $this->validPayload(
                [$grn['grn']->id],
                [
                    'apply_sscl' => true,
                    'apply_vat'  => true,
                    'items'      => [[
                        'grn_item_id'        => $grn['item']->id,
                        'margin_amount_base' => 60,
                        'expenses'           => [
                            ['expense_type_id' => $this->freight->id, 'amount' => 150],
                            ['expense_type_id' => $this->duty->id,    'amount' => 50],
                        ],
                    ]],
                ],
            ))
            ->assertCreated();

        $item = $response->json('data.items.0');
        $this->assertEquals(200.0, $item['expense_total_base']);
        $this->assertEquals(1200.0, $item['landed_unit_cost']);
        $this->assertEquals(16.0, $item['sscl_amount_base']);       // 1.25% of (fob + expenses + margin) = 15.75, ceiled
        $this->assertEquals(60.0, $item['margin_amount_base']);
        $this->assertEquals(1276.0, $item['before_tax_price_base']);
        $this->assertEquals(229.68, $item['vat_amount_base']);      // 18% of before-tax
        $this->assertEquals(1505.68, $item['selling_price_base']);

        // The typed expense rows round-trip
        $expenses = collect($item['expenses'])->keyBy('expense_type_id');
        $this->assertEquals(150.0, $expenses[$this->freight->id]['amount']);
        $this->assertEquals(50.0, $expenses[$this->duty->id]['amount']);
        $this->assertSame(2, DB::table('inv_costing_item_expenses')->count());

        // Header totals: before-tax feeds non-VAT invoices, after-tax feeds VAT invoices
        $this->assertEquals(10000.0, $response->json('data.material_cost'));
        $this->assertEquals(2000.0, $response->json('data.total_additional_expenses'));
        $this->assertEquals(12000.0, $response->json('data.total_landed_cost'));
        $this->assertEquals(160.0, $response->json('data.sscl_amount'));
        $this->assertEquals(12760.0, $response->json('data.gross_fob_cif_value')); // Before-Tax Value
        $this->assertEquals(2296.8, $response->json('data.vat_amount'));
        $this->assertEquals(15056.8, $response->json('data.total_price_with_vat')); // After-Tax Value
    }

    public function test_sscl_applies_to_the_full_cost_plus_margin_sum(): void
    {
        $product = Product::factory()->create();
        $grn = $this->makeGrn($product, qty: 10, unitPrice: 1000);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/costings', $this->validPayload(
                [$grn['grn']->id],
                [
                    'apply_sscl' => true,
                    'items'      => [[
                        'grn_item_id' => $grn['item']->id,
                        'expenses'    => [['expense_type_id' => $this->freight->id, 'amount' => 500]],
                    ]],
                ],
            ))
            ->assertCreated();

        // 1.25% of (1000 + 500) = 18.75, ceiled to 19 — the expenses ARE part of the SSCL base
        $this->assertEquals(19.0, $response->json('data.items.0.sscl_amount_base'));
        $this->assertEquals(1519.0, $response->json('data.items.0.before_tax_price_base'));
    }

    public function test_line_sscl_and_vat_overrides_beat_header_defaults(): void
    {
        $product = Product::factory()->create();
        $grnA = $this->makeGrn($product, qty: 5, unitPrice: 1000);
        $grnB = $this->makeGrn($product, qty: 5, unitPrice: 1000);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/costings', $this->validPayload(
                [$grnA['grn']->id, $grnB['grn']->id],
                [
                    'apply_sscl' => true,
                    'apply_vat'  => true,
                    'items'      => [
                        ['grn_item_id' => $grnA['item']->id, 'sscl_pct' => 5, 'vat_pct' => 8],
                    ],
                ],
            ))
            ->assertCreated();

        $items = collect($response->json('data.items'))->keyBy('grn_item_id');

        $a = $items[$grnA['item']->id]; // overridden: sscl 5% = 50, before 1050, vat 8% = 84
        $this->assertEquals(50.0, $a['sscl_amount_base']);
        $this->assertEquals(84.0, $a['vat_amount_base']);
        $this->assertEquals(1134.0, $a['selling_price_base']);

        $b = $items[$grnB['item']->id]; // defaults: sscl 1.25% = 12.50 ceiled to 13, before 1013, vat 18% = 182.34
        $this->assertEquals(13.0, $b['sscl_amount_base']);
        $this->assertEquals(182.34, $b['vat_amount_base']);
        $this->assertEquals(1195.34, $b['selling_price_base']);
    }

    public function test_sscl_and_vat_toggles_off_leave_before_and_after_tax_equal(): void
    {
        $product = Product::factory()->create();
        $grn = $this->makeGrn($product, qty: 10, unitPrice: 1000);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/costings', $this->validPayload(
                [$grn['grn']->id],
                [
                    'items' => [[
                        'grn_item_id'        => $grn['item']->id,
                        'margin_amount_base' => 100,
                        'expenses'           => [['expense_type_id' => $this->freight->id, 'amount' => 200]],
                    ]],
                ],
            ))
            ->assertCreated();

        $item = $response->json('data.items.0');
        $this->assertEquals(0.0, $item['sscl_amount_base']);
        $this->assertEquals(0.0, $item['vat_amount_base']);
        $this->assertEquals(1300.0, $item['before_tax_price_base']);
        $this->assertEquals(1300.0, $item['selling_price_base']);
    }

    // ── Lifecycle ───────────────────────────────────────────────────────────

    public function test_update_rebuilds_breakdown_and_is_draft_only(): void
    {
        $product = Product::factory()->create();
        $grn = $this->makeGrn($product, qty: 10, unitPrice: 1000);

        $lineWith = fn (float $margin): array => [[
            'grn_item_id'        => $grn['item']->id,
            'margin_amount_base' => $margin,
            'expenses'           => [['expense_type_id' => $this->freight->id, 'amount' => 100]],
        ]];

        $created = $this->actingAs($this->user)
            ->postJson('/api/v1/costings', $this->validPayload([$grn['grn']->id], ['items' => $lineWith(50)]))
            ->assertCreated();
        $id = $created->json('data.id');

        // Re-save with a different margin — breakdown and expense rows rebuild
        $this->actingAs($this->user)
            ->putJson("/api/v1/costings/{$id}", $this->validPayload([$grn['grn']->id], ['items' => $lineWith(200)]))
            ->assertOk()
            ->assertJsonPath('data.items.0.selling_price_base', 1300);

        $this->assertSame(1, DB::table('inv_costing_item_expenses')->where('costing_id', $id)->count());

        $this->actingAs($this->user)->postJson("/api/v1/costings/{$id}/confirm")->assertOk();

        $this->actingAs($this->user)
            ->putJson("/api/v1/costings/{$id}", $this->validPayload([$grn['grn']->id]))
            ->assertStatus(422);
    }

    public function test_confirm_syncs_price_list_and_locks_grn(): void
    {
        $product = Product::factory()->create();
        $pivotId = $this->givePriceList($product, selling: 999, cost: 900);
        $grn = $this->makeGrn($product, qty: 10, unitPrice: 1000);

        $created = $this->actingAs($this->user)
            ->postJson('/api/v1/costings', $this->validPayload(
                [$grn['grn']->id],
                [
                    'items' => [[
                        'grn_item_id'        => $grn['item']->id,
                        'margin_amount_base' => 220,
                        'expenses'           => [['expense_type_id' => $this->freight->id, 'amount' => 100]],
                    ]],
                ],
            ))
            ->assertCreated();

        $this->actingAs($this->user)
            ->postJson('/api/v1/costings/' . $created->json('data.id') . '/confirm')
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        // landed 1100 → cost_price ; after-tax selling 1320 → selling_price
        $pivot = DB::table('inv_product_sales_channels')->find($pivotId);
        $this->assertSame(1100.0, (float) $pivot->cost_price);
        $this->assertSame(1320.0, (float) $pivot->selling_price);

        // The GRN is now locked — a second costing on it cannot confirm
        $second = $this->actingAs($this->user)
            ->postJson('/api/v1/costings', $this->validPayload([$grn['grn']->id]))
            ->assertCreated();

        $this->actingAs($this->user)
            ->postJson('/api/v1/costings/' . $second->json('data.id') . '/confirm')
            ->assertStatus(422);
    }

    // ── Preview ─────────────────────────────────────────────────────────────

    public function test_calculate_preview_returns_breakdown_without_persisting(): void
    {
        $product = Product::factory()->create();
        $grn = $this->makeGrn($product, qty: 10, unitPrice: 1000);

        // fob 1000 + exp 200 + margin 80 = 1280 ; sscl 1.25% × 1280 = 16 → before-tax 1296
        $this->actingAs($this->user)
            ->postJson('/api/v1/costings/calculate-preview', [
                'grn_ids'    => [$grn['grn']->id],
                'apply_sscl' => true,
                'sscl_pct'   => 1.25,
                'apply_vat'  => false,
                'items'      => [[
                    'grn_item_id'        => $grn['item']->id,
                    'margin_amount_base' => 80,
                    'expenses'           => [['expense_type_id' => $this->freight->id, 'amount' => 200]],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.items.0.landed_unit_cost', 1200)
            ->assertJsonPath('data.items.0.before_tax_price_base', 1296)
            ->assertJsonPath('data.items.0.selling_price_base', 1296)
            ->assertJsonPath('data.summary.total_price_with_vat', 12960)
            ->assertJsonPath('data.summary.gross_fob_cif_value', 12960);

        $this->assertSame(0, Costing::count());
    }
}
