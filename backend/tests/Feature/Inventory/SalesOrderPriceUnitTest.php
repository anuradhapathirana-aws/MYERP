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
use Modules\Inventory\Models\SalesOrder;
use Modules\Inventory\Models\UnitCategory;
use Modules\Inventory\Models\UnitConversion;
use Modules\Inventory\Models\UnitType;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A line's price may be quoted per a DIFFERENT unit than its quantity
 * (price_unit_id): fabric bought by the metre sells its yards at the per-metre
 * price. The line amount is quantity x unit_price with NO conversion — 100 yd
 * x 500/m bills 50,000 while only ~91.44 m leave stock. That gap is the margin
 * the business intends, and it must survive from the SO to the invoice.
 */
class SalesOrderPriceUnitTest extends TestCase
{
    use RefreshDatabase;

    /** 1 m = 1.09361 yd (and back), the rate a user would enter in Unit Conversions. */
    private const YARDS_PER_METRE = 1.09361;

    private User $user;
    private CustomerMaster $customer;
    private UnitType $metre;
    private UnitType $yard;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view_sales_orders', 'create_sales_orders',
            'view_invoices', 'create_invoices',
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

        // Length category, stocked in metres, sellable in yards.
        $length      = UnitCategory::create(['name' => 'Length']);
        $this->metre = UnitType::create(['unit_category_id' => $length->id, 'name' => 'Metre', 'symbol' => 'm']);
        $this->yard  = UnitType::create(['unit_category_id' => $length->id, 'name' => 'Yard', 'symbol' => 'yd']);
        $length->update(['base_unit_type_id' => $this->metre->id]);

        UnitConversion::create([
            'from_unit_type_id' => $this->metre->id,
            'to_unit_type_id'   => $this->yard->id,
            'multiplier'        => self::YARDS_PER_METRE,
        ]);
        UnitConversion::create([
            'from_unit_type_id' => $this->yard->id,
            'to_unit_type_id'   => $this->metre->id,
            'multiplier'        => 1 / self::YARDS_PER_METRE,
        ]);
    }

    /** A metre-stocked product with one in-stock roll of the given length. */
    private function productWithRoll(float $metres): array
    {
        static $seq = 0;
        $seq++;

        $product = Product::factory()->create(['base_unit_type_id' => $this->metre->id]);

        $grn = GoodsReceivedNote::create([
            'grn_no'   => sprintf('GRN-PU-%04d', $seq),
            'grn_date' => '2026-07-01',
            'status'   => 'confirmed',
        ]);
        $grnItem = GoodsReceivedNoteItem::create([
            'grn_id'            => $grn->id,
            'product_id'        => $product->id,
            'unit_id'           => $this->metre->id,
            'quantity_received' => $metres,
            'base_quantity'     => $metres,
            'conversion_factor' => 1,
            'unit_price'        => 400,
        ]);
        $roll = GrnItemPiece::create([
            'grn_item_id' => $grnItem->id,
            'grn_id'      => $grn->id,
            'product_id'  => $product->id,
            'piece_no'    => 1,
            'roll_no'     => 'R-1',
            'weight'      => $metres,
            'piece_code'  => sprintf('%s-I001-P001', $grn->grn_no),
            'status'      => GrnItemPiece::STATUS_IN_STOCK,
        ]);

        return ['product' => $product, 'roll' => $roll];
    }

    /** @param array<string, mixed> $lineOverrides */
    private function postSo(Product $product, GrnItemPiece $roll, array $lineOverrides = [], string $status = 'confirmed')
    {
        return $this->actingAs($this->user)->postJson('/api/v1/sales-orders', [
            'order_date'      => '2026-07-16',
            'customer_id'     => $this->customer->id,
            'sales_person_id' => $this->user->id,
            'status'          => $status,
            'items'           => [array_merge([
                'product_id'    => $product->id,
                'unit_id'       => $this->yard->id,
                'price_unit_id' => $this->metre->id,
                'quantity'      => 100,
                'unit_price'    => 500,
                'piece_codes'   => [$roll->piece_code],
            ], $lineOverrides)],
        ]);
    }

    // ── The cheat itself ─────────────────────────────────────────────────────

    public function test_yard_quantity_bills_at_per_metre_price_without_conversion(): void
    {
        ['product' => $product, 'roll' => $roll] = $this->productWithRoll(100.0);

        $response = $this->postSo($product, $roll);
        $response->assertCreated();

        $so   = SalesOrder::with('items')->findOrFail($response->json('data.id'));
        $item = $so->items->first();

        // 100 yd x 500/m = 50,000 — the numbers multiply as-is, no yd->m conversion.
        $this->assertSame(50000.0, (float) $item->line_total);
        $this->assertSame(50000.0, (float) $so->subtotal);
        $this->assertSame((int) $this->metre->id, (int) $item->price_unit_id);

        // ...while stock still converts: only ~91.44 m are committed.
        $this->assertEqualsWithDelta(100 / self::YARDS_PER_METRE, (float) $item->base_quantity, 0.001);
    }

    public function test_price_unit_id_is_returned_by_the_show_endpoint(): void
    {
        ['product' => $product, 'roll' => $roll] = $this->productWithRoll(100.0);

        $soId = $this->postSo($product, $roll)->json('data.id');

        $this->actingAs($this->user)
            ->getJson("/api/v1/sales-orders/{$soId}")
            ->assertOk()
            ->assertJsonPath('data.items.0.price_unit_id', $this->metre->id)
            ->assertJsonPath('data.items.0.price_unit.symbol', 'm');
    }

    public function test_line_without_price_unit_keeps_legacy_behaviour(): void
    {
        ['product' => $product, 'roll' => $roll] = $this->productWithRoll(100.0);

        $response = $this->postSo($product, $roll, ['price_unit_id' => null]);
        $response->assertCreated();

        $item = SalesOrder::with('items')->findOrFail($response->json('data.id'))->items->first();

        $this->assertNull($item->price_unit_id);
        $this->assertSame(50000.0, (float) $item->line_total);
    }

    public function test_price_unit_without_a_conversion_to_the_stocking_uom_is_rejected(): void
    {
        ['product' => $product, 'roll' => $roll] = $this->productWithRoll(100.0);

        $weight = UnitCategory::create(['name' => 'Weight']);
        $kg     = UnitType::create(['unit_category_id' => $weight->id, 'name' => 'Kilogram', 'symbol' => 'kg']);

        $this->postSo($product, $roll, ['price_unit_id' => $kg->id])
            ->assertStatus(422);

        $this->assertDatabaseCount('inv_sales_orders', 0);
    }

    public function test_selling_a_rolls_full_length_in_yards_survives_rounding(): void
    {
        // The form auto-fills a scanned line with the roll's capacity converted to
        // yards and rounded to the 4 decimals the input holds — 25 m becomes
        // 27.3402 or 27.3403 yd depending on which side the rounding falls. Both
        // convert back to a hair off 25 m (±0.000046), which is dust the roll
        // distribution snaps away — the mismatch guard must forgive it too.
        foreach ([27.3402, 27.3403] as $qtyYards) {
            ['product' => $product, 'roll' => $roll] = $this->productWithRoll(25.0);

            $response = $this->postSo($product, $roll, ['quantity' => $qtyYards]);
            $response->assertCreated();

            $item = SalesOrder::with('items')->findOrFail($response->json('data.id'))->items->first();

            // The rolls are the physical truth: exactly 25 m leaves stock.
            $this->assertSame(25.0, (float) $item->base_quantity);
        }
    }

    // ── Flow-through to the invoice ──────────────────────────────────────────

    public function test_non_tax_invoice_bills_yard_quantity_at_the_per_metre_price(): void
    {
        ['product' => $product, 'roll' => $roll] = $this->productWithRoll(100.0);

        $soId = $this->postSo($product, $roll)->json('data.id');

        $invoice = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
            'so_id'        => $soId,
            'invoice_date' => '2026-07-16',
            'invoice_type' => 'non_tax',
        ]);
        $invoice->assertCreated();

        $itemId = $invoice->json('data.items.0.id');
        $this->assertNotNull($itemId);

        $row = DB::table('inv_invoice_items')->where('id', $itemId)->first();

        // Quantity stays in yards, the price stays the per-metre figure, and the
        // amount is their raw product — exactly what the SO promised.
        $this->assertSame(100.0, (float) $row->quantity);
        $this->assertSame((int) $this->yard->id, (int) $row->unit_id);
        $this->assertSame((int) $this->metre->id, (int) $row->price_unit_id);
        $this->assertSame(500.0, (float) $row->unit_price);
        $this->assertSame(50000.0, (float) $row->line_total);
    }

    public function test_tax_invoice_restates_the_costing_price_per_the_price_unit(): void
    {
        ['product' => $product, 'roll' => $roll] = $this->productWithRoll(100.0);

        // A confirmed costing priced this shipment per the stocking UOM (m):
        // before-tax 500/m, after-tax 590/m at 18% VAT.
        $costingId = DB::table('inv_costings')->insertGetId([
            'document_no'  => 'CST-PU-1',
            'reference_no' => 'CRef-PU-1',
            'supplier_id'  => 1,
            'costing_type' => 'fob',
            'status'       => 'confirmed',
            'apply_vat'    => 1,
            'vat_pct'      => 18,
            'confirmed_at' => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        DB::table('inv_costing_items')->insert([
            'costing_id'            => $costingId,
            'grn_id'                => $roll->grn_id,
            'grn_item_id'           => $roll->grn_item_id,
            'product_id'            => $product->id,
            'quantity'              => 100,
            'conversion_factor'     => 1,
            'base_quantity'         => 100,
            'unit_price'            => 400,
            'before_tax_price'      => 500,
            'before_tax_price_base' => 500,
            'selling_price'         => 590,
            'selling_price_base'    => 590,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $soId = $this->postSo($product, $roll, ['unit_price' => 590])->json('data.id');

        $invoice = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
            'so_id'        => $soId,
            'invoice_date' => '2026-07-16',
            'invoice_type' => 'tax',
        ]);
        $invoice->assertCreated();

        $row = DB::table('inv_invoice_items')->where('id', $invoice->json('data.items.0.id'))->first();

        // The price unit is the metre, so the costing's per-metre before-tax price
        // bills AS-IS against the yard quantity — no x0.9144 restatement.
        $this->assertSame(500.0, (float) $row->unit_price);
        $this->assertSame(18.0, (float) $row->tax);
        // 100 yd x 500/m = 50,000 + 18% VAT = 59,000
        $this->assertSame(59000.0, (float) $row->line_total);
    }
}
