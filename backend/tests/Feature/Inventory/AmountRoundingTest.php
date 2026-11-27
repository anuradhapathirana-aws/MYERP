<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\AmountPrecision;
use App\Services\SettingsService;
use App\Support\Money\Amount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductLocationStore;
use Modules\Inventory\Models\SalesOrder;
use Modules\Inventory\Services\PurchaseLinePricer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 2 of the Amount decimals setting: new documents round their money
 * amounts to the setting. Unit costs and quantities keep their precision.
 */
class AmountRoundingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private CustomerMaster $customer;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['view_sales_orders', 'create_sales_orders', 'edit_sales_orders'] as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo(['view_sales_orders', 'create_sales_orders', 'edit_sales_orders']);

        $this->customer = CustomerMaster::create([
            'customer_code' => 'CUS-0001',
            'customer_name' => 'Test Customer',
            'customer_type' => 'Retail',
        ]);
    }

    private function useDecimals(int $decimals): void
    {
        app(SettingsService::class)->set(AmountPrecision::SETTING_KEY, $decimals);
        app()->forgetScopedInstances();
    }

    /** @param array<array<string, mixed>> $lines */
    private function postSo(array $lines, float $transport = 0): SalesOrder
    {
        $items = [];
        foreach ($lines as $line) {
            $product = Product::factory()->create();
            ProductLocationStore::create(['product_id' => $product->id, 'current_stock' => 100000]);
            // VAT-free: these tests are about rounding; the SO total would otherwise add VAT
            $items[] = ['product_id' => $product->id, 'discount' => 0, 'tax' => 0, 'vat_pct' => 0, ...$line];
        }

        $id = $this->actingAs($this->user)->postJson('/api/v1/sales-orders', [
            'order_date'       => '2026-11-22',
            'customer_id'      => $this->customer->id,
            'customer_type'    => 'Retail',
            'sales_person_id'  => $this->user->id,
            'transport_charge' => $transport,
            'items'            => $items,
        ])->assertCreated()->json('data.id');

        return SalesOrder::with('items')->findOrFail($id);
    }

    // ── Sales Order: rounds like the Invoice ───────────────────────────────────

    public function test_sales_order_rounds_the_selling_price_so_the_printed_line_adds_up(): void
    {
        // The live-data case: a 4dp costing price that drifted 4.80 on paper.
        $so   = $this->postSo([['quantity' => 1200, 'unit_price' => 2775.124]]);
        $line = $so->items->first();

        $this->assertSame(2775.12, (float) $line->unit_price);
        $this->assertSame(3330144.0, (float) $line->line_total);   // 1,200 x 2,775.12
        $this->assertSame(3330144.0, (float) $so->subtotal);
        $this->assertSame(3330144.0, (float) $so->grand_total);
    }

    public function test_sales_order_totals_are_the_sum_of_rounded_lines(): void
    {
        $so = $this->postSo([
            ['quantity' => 3, 'unit_price' => 33.333, 'discount' => 10],   // 99.99 less 10% = 89.991
            ['quantity' => 1.5, 'unit_price' => 10.005],                    // 10.01 x 1.5 = 15.015
        ], transport: 250.555);

        [$a, $b] = [$so->items[0], $so->items[1]];

        $this->assertSame(89.99, (float) $a->line_total);
        $this->assertSame(15.02, (float) $b->line_total);
        $this->assertSame(250.56, (float) $so->transport_charge);
        $this->assertSame(Amount::round(89.99 + 15.02 + 250.56), (float) $so->grand_total);
    }

    public function test_sales_order_follows_a_zero_decimal_setting(): void
    {
        $this->useDecimals(0);

        $so   = $this->postSo([['quantity' => 3, 'unit_price' => 99.6]]);
        $line = $so->items->first();

        $this->assertSame(100.0, (float) $line->unit_price);
        $this->assertSame(300.0, (float) $line->line_total);
        $this->assertSame(300.0, (float) $so->grand_total);
    }

    public function test_quantity_keeps_its_own_precision(): void
    {
        $so = $this->postSo([['quantity' => 12.3456, 'unit_price' => 10]]);

        $this->assertSame(12.3456, (float) $so->items->first()->quantity);
        $this->assertSame(123.46, (float) $so->items->first()->line_total);
    }

    // ── Purchase lines (GRN) ───────────────────────────────────────────────────

    public function test_a_purchase_line_without_tax_rounds_its_total_but_keeps_the_unit_price(): void
    {
        $priced = app(PurchaseLinePricer::class)->price(3, 10.3333, 0, null, false);

        $this->assertSame(10.3333, $priced->unitPrice);   // a cost: full precision
        $this->assertSame(31.0, $priced->lineTotal);      // 30.9999 rounded
    }

    // ── The helper ──────────────────────────────────────────────────────────────

    public function test_amount_helper_follows_the_setting(): void
    {
        $this->assertSame(0.01, Amount::unit());
        $this->assertSame(0.005, Amount::tolerance());
        $this->assertSame('1234.50', Amount::fixed(1234.5));
        $this->assertSame('1,234.50', Amount::display(1234.5));

        $this->useDecimals(0);

        $this->assertSame(1.0, Amount::unit());
        $this->assertSame('1235', Amount::fixed(1234.5));
        $this->assertSame('1,235', Amount::display(1234.5));
    }
}
