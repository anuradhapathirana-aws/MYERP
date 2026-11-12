<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\PaymentMode;
use App\Models\User;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Models\Invoice;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductLocationStore;
use Modules\Inventory\Models\StockReferenceType;
use Modules\Inventory\Models\StockTransaction;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The home dashboard's Inventory sections. Pinned: which documents count
 * (never drafts or cancelled), that returns come off sales, that balances use
 * the canonical outstanding formula, the aging buckets, and that every section
 * is guarded by its own screen's permission.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'view_dashboard', 'view_invoices', 'view_customer_receipts',
        'view_supplier_payments', 'view_reports', 'view_sales_orders', 'view_delivery_orders',
    ];

    private User $user;
    private CustomerMaster $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-12 10:00:00'));

        app(SettingsService::class)->set('module.inventory', true);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo(self::PERMISSIONS);

        $this->customer = CustomerMaster::create([
            'customer_code' => 'CUS-0001',
            'customer_name' => 'Acme Traders',
            'customer_type' => 'Retail',
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function invoice(float $total, string $date, string $status = 'issued', ?string $due = null): Invoice
    {
        static $seq = 0;
        $seq++;

        return Invoice::create([
            'invoice_no'   => sprintf('INV-DB-%04d', $seq),
            'so_id'        => $seq,
            'customer_id'  => $this->customer->id,
            'invoice_date' => $date,
            'due_date'     => $due,
            'status'       => $status,
            'invoice_type' => 'non_tax',
            'subtotal'     => $total,
            'grand_total'  => $total,
        ]);
    }

    private function customerReturn(Invoice $invoice, float $amount, string $date, string $status = 'confirmed'): void
    {
        static $seq = 0;
        $seq++;

        DB::table('inv_customer_returns')->insert([
            'return_no'          => sprintf('CR-DB-%04d', $seq),
            'return_date'        => $date,
            'customer_id'        => $this->customer->id,
            'invoice_id'         => $invoice->id,
            'store_id'           => 1,
            'location_id'        => 1,
            'status'             => $status,
            'total_amount'       => $amount,
            'applied_to_invoice' => $amount,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    private function receipt(Invoice $invoice, float $amount, string $date, string $status = 'confirmed'): void
    {
        static $seq = 0;
        $seq++;

        $mode = PaymentMode::firstOrCreate(['code' => 'CASH'], ['payment_mode_name' => 'Cash', 'is_active' => true]);

        $receiptId = DB::table('inv_customer_receipts')->insertGetId([
            'receipt_no'   => sprintf('RCT-DB-%04d', $seq),
            'receipt_date' => $date,
            'customer_id'  => $this->customer->id,
            'gross_amount' => $amount,
            'net_amount'   => $amount,
            'status'       => $status,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('inv_customer_receipt_allocations')->insert([
            'receipt_id'     => $receiptId,
            'reference_type' => 'invoice',
            'reference_id'   => $invoice->id,
            'receipt_amount' => $amount,
            'discount'       => 0,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        DB::table('inv_customer_receipt_settlements')->insert([
            'receipt_id'        => $receiptId,
            'payment_mode_id'   => $mode->id,
            'payment_mode_code' => 'cash',
            'payment_mode_name' => 'Cash',
            'amount'            => $amount,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    private function grn(float $total, string $date, ?int $supplierId, string $status = 'confirmed', ?string $terms = null): void
    {
        static $seq = 0;
        $seq++;

        DB::table('inv_goods_received_notes')->insert([
            'grn_no'       => sprintf('GRN-DB-%04d', $seq),
            'supplier_id'  => $supplierId,
            'grn_date'     => $date,
            'status'       => $status,
            'total_amount' => $total,
            'payment_terms' => $terms,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    // ── Sales & Collections ──────────────────────────────────────────────────

    public function test_sales_counts_billed_invoices_less_returns_and_compares_with_last_month(): void
    {
        $issued = $this->invoice(1000, '2026-11-03');
        $this->invoice(500, '2026-11-10', 'paid');
        $this->invoice(999, '2026-11-05', 'draft');
        $this->invoice(777, '2026-11-06', 'cancelled');
        $this->invoice(300, '2026-10-05');            // same days last month
        $this->invoice(4000, '2026-10-25');           // after the 12th: not in the comparison

        $this->customerReturn($issued, 200, '2026-11-08');
        $this->customerReturn($issued, 50, '2026-11-09', 'draft');
        $this->receipt($issued, 400, '2026-11-11');
        $this->receipt($issued, 90, '2026-11-11', 'draft');

        $data = $this->actingAs($this->user)
            ->getJson('/api/v1/dashboard/inventory/sales?period=month')
            ->assertOk()
            ->json('data');

        $this->assertEquals(1500, $data['kpis']['invoiced']);
        $this->assertEquals(200, $data['kpis']['returns']);
        $this->assertEquals(1300, $data['kpis']['net_sales']);
        $this->assertSame(2, $data['kpis']['invoice_count']);
        $this->assertEquals(300, $data['kpis']['net_sales_previous']);
        $this->assertEquals(400, $data['kpis']['collections']);
        $this->assertSame([['mode' => 'Cash', 'amount' => 400]], $data['collection_modes']);

        $this->assertCount(12, $data['trend']);
        $this->assertSame('2026-11', end($data['trend'])['month']);
        $this->assertEquals(1300, end($data['trend'])['sales']);
        $this->assertEquals(4300, $data['trend'][10]['sales']);

        $this->assertSame('Acme Traders', $data['top_customers'][0]['name']);
        $this->assertEquals(1500, $data['top_customers'][0]['amount']);
    }

    public function test_a_return_is_deducted_from_its_invoices_period_like_the_sales_summary(): void
    {
        $october = $this->invoice(1000, '2026-10-19');
        $this->invoice(500, '2026-11-02');
        $this->customerReturn($october, 200, '2026-11-05');   // October invoice, returned in November

        $data = $this->actingAs($this->user)
            ->getJson('/api/v1/dashboard/inventory/sales?period=month')
            ->assertOk()
            ->json('data');

        $this->assertEquals(500, $data['kpis']['net_sales']);
        $this->assertEquals(0, $data['kpis']['returns']);
        $this->assertEquals(1000, $data['trend'][10]['sales']);   // October as run at month end

        $year = $this->actingAs($this->user)
            ->getJson('/api/v1/dashboard/inventory/sales?period=year')
            ->json('data.kpis');

        $this->assertEquals(1300, $year['net_sales']);
    }

    public function test_period_today_only_counts_todays_invoices(): void
    {
        $this->invoice(250, '2026-11-12');
        $this->invoice(1000, '2026-11-11');

        $this->actingAs($this->user)
            ->getJson('/api/v1/dashboard/inventory/sales?period=today')
            ->assertOk()
            ->assertJsonPath('data.kpis.net_sales', 250)
            ->assertJsonPath('data.period.from', '2026-11-12');
    }

    public function test_collections_are_hidden_without_customer_receipt_permission(): void
    {
        $this->user->revokePermissionTo('view_customer_receipts');
        $this->receipt($this->invoice(1000, '2026-11-03'), 400, '2026-11-11');

        $data = $this->actingAs($this->user->fresh())
            ->getJson('/api/v1/dashboard/inventory/sales')
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('collections', $data['kpis']);
        $this->assertNull($data['collection_modes']);
        $this->assertNull($data['trend'][0]['collections']);
    }

    public function test_invalid_period_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/dashboard/inventory/sales?period=decade')
            ->assertStatus(422);
    }

    // ── Receivables & Payables ───────────────────────────────────────────────

    public function test_receivables_are_aged_from_the_due_date(): void
    {
        $this->invoice(1000, '2026-11-01', 'issued', '2026-11-20');   // not yet due
        $late = $this->invoice(500, '2026-09-01', 'issued', '2026-10-01'); // 42 days late
        $this->receipt($late, 100, '2026-10-15');
        $this->invoice(800, '2026-09-01', 'paid', '2026-10-01');       // paid: owes nothing

        $data = $this->actingAs($this->user)
            ->getJson('/api/v1/dashboard/inventory/receivables')
            ->assertOk()
            ->json('data');

        $aging = collect($data['aging'])->pluck('amount', 'key');

        $this->assertEquals(1400, $data['total']);
        $this->assertEquals(400, $data['overdue']);
        $this->assertSame(2, $data['documents']);
        $this->assertEquals(1000, $aging['current']);
        $this->assertEquals(400, $aging['31_60']);
        $this->assertEquals(1400, $data['top'][0]['amount']);
    }

    public function test_an_invoice_without_a_due_date_is_never_called_overdue(): void
    {
        // Same as the Outstanding Summary report: no due date = no days overdue.
        $this->invoice(300, '2026-08-01');

        $data = $this->actingAs($this->user)
            ->getJson('/api/v1/dashboard/inventory/receivables')
            ->assertOk()
            ->json('data');

        $this->assertEquals(300, collect($data['aging'])->pluck('amount', 'key')['no_due']);
        $this->assertEquals(0, $data['overdue']);
        $this->assertEquals(300, $data['total']);
    }

    public function test_payables_age_from_grn_date_plus_supplier_credit_period(): void
    {
        $supplierId = (int) DB::table('inv_supplier_masters')->insertGetId([
            'supplier_name' => 'Cotton Mills',
            'credit_period' => 30,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->grn(1000, '2026-09-01', $supplierId);            // due 1 Oct → 42 days late
        $this->grn(600, '2026-11-01', $supplierId);             // due 1 Dec → current
        $this->grn(5000, '2026-09-01', $supplierId, 'draft');   // never counts

        $data = $this->actingAs($this->user)
            ->getJson('/api/v1/dashboard/inventory/payables')
            ->assertOk()
            ->json('data');

        $aging = collect($data['aging'])->pluck('amount', 'key');

        $this->assertEquals(1600, $data['total']);
        $this->assertEquals(1000, $data['overdue']);
        $this->assertEquals(1000, $aging['31_60']);
        $this->assertEquals(600, $aging['current']);
        $this->assertSame('Cotton Mills', $data['top'][0]['name']);
    }

    public function test_a_grns_own_payment_terms_win_over_the_supplier_credit_period(): void
    {
        $noTerms = (int) DB::table('inv_supplier_masters')->insertGetId([
            'supplier_name' => 'No Terms Ltd', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->grn(1000, '2026-10-15', $noTerms, 'confirmed', 'net_90');  // due 13 Jan → current
        $this->grn(400, '2026-11-01', $noTerms, 'confirmed', 'cod');      // due on the day → 11 days late
        $this->grn(250, '2026-08-01', $noTerms);                          // no terms anywhere

        $data = $this->actingAs($this->user)
            ->getJson('/api/v1/dashboard/inventory/payables')
            ->assertOk()
            ->json('data');

        $aging = collect($data['aging'])->pluck('amount', 'key');

        $this->assertEquals(1000, $aging['current']);
        $this->assertEquals(400, $aging['1_30']);
        $this->assertEquals(250, $aging['no_due']);
        $this->assertEquals(400, $data['overdue']);
    }

    // ── Stock & Purchasing ───────────────────────────────────────────────────

    public function test_stock_flags_low_and_out_of_stock_and_values_on_hand_stock(): void
    {
        $locationId = (int) DB::table('inv_locations')->insertGetId([
            'company_id' => 1, 'industry_id' => 1, 'location_code' => 'LOC-1', 'location_name' => 'Main',
            'country' => 'LK', 'loc_street_address' => 'S', 'loc_city' => 'C', 'loc_country' => 'LK',
            'loc_state' => 'S', 'loc_postal_zip_code' => '0', 'financial_year' => '2026',
            'stock_releasing_method' => 'FIFO', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $storeId = (int) DB::table('inv_stores')->insertGetId([
            'store_type_id' => 1, 'location_id' => $locationId, 'store_code' => 'STR-1',
            'store_name' => 'Main Store', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $out  = Product::factory()->create(['reorder_level' => 10]);
        $low  = Product::factory()->create(['reorder_level' => 10]);
        $fine = Product::factory()->create(['reorder_level' => 10]);

        foreach ([[$low, 5], [$fine, 50]] as [$product, $qty]) {
            ProductLocationStore::create([
                'product_id' => $product->id, 'location_id' => $locationId,
                'store_id' => $storeId, 'current_stock' => $qty,
            ]);
            StockTransaction::create([
                'transaction_date' => '2026-11-01 09:00:00',
                'reference_type'   => StockReferenceType::CODE_GRN,
                'reference_id'     => $product->id,
                'product_id'       => $product->id,
                'location_id'      => $locationId,
                'qty_in'           => $qty,
                'qty_out'          => 0,
                'unit_price'       => 20,
                'unit_id'          => $product->base_unit_type_id,
            ]);
        }

        $this->grn(700, '2026-11-05', null);

        $data = $this->actingAs($this->user)
            ->getJson('/api/v1/dashboard/inventory/stock')
            ->assertOk()
            ->json('data');

        $this->assertEquals(1100, $data['stock_value']);   // (5 + 50) × 20
        $this->assertSame(1, $data['alerts']['out_count']);
        $this->assertSame(1, $data['alerts']['low_count']);
        $this->assertSame($out->id, $data['alerts']['items'][0]['id']);
        $this->assertSame('out', $data['alerts']['items'][0]['status']);
        $this->assertSame(1, $data['purchasing']['grn_count']);
        $this->assertEquals(700, $data['purchasing']['grn_value']);
        $this->assertCount(6, $data['purchase_trend']);
        $this->assertCount(1, $data['recent_grns']);
    }

    // ── Sales Orders & Deliveries ────────────────────────────────────────────

    private function salesOrder(string $status, float $total, string $date): int
    {
        static $seq = 0;
        $seq++;

        return (int) DB::table('inv_sales_orders')->insertGetId([
            'so_no'           => sprintf('SO-DB-%04d', $seq),
            'customer_id'     => $this->customer->id,
            'sales_person_id' => $this->user->id,
            'order_date'      => $date,
            'status'          => $status,
            'grand_total'     => $total,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    private function deliveryOrder(int $soId, string $status, string $date): void
    {
        static $seq = 0;
        $seq++;

        DB::table('inv_delivery_orders')->insert([
            'do_no'         => sprintf('DO-DB-%04d', $seq),
            'so_id'         => $soId,
            'customer_id'   => $this->customer->id,
            'delivery_date' => $date,
            'status'        => $status,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function test_orders_are_counted_by_status_in_the_period(): void
    {
        $so = $this->salesOrder('confirmed', 1000, '2026-11-02');
        $this->salesOrder('confirmed', 500, '2026-11-05');
        $this->salesOrder('draft', 200, '2026-11-06');
        $this->salesOrder('confirmed', 9999, '2026-10-30');   // last month: outside

        $this->deliveryOrder($so, 'confirmed', '2026-11-03');
        $this->deliveryOrder($so, 'draft', '2026-11-04');

        $data = $this->actingAs($this->user)
            ->getJson('/api/v1/dashboard/inventory/orders?period=month')
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['sales_orders']['confirmed']['count']);
        $this->assertEquals(1500, $data['sales_orders']['confirmed']['value']);
        $this->assertSame(1, $data['sales_orders']['draft']['count']);
        $this->assertSame(0, $data['sales_orders']['cancelled']['count']);
        $this->assertSame(['draft' => 1, 'confirmed' => 1, 'cancelled' => 0], $data['delivery_orders']);
    }

    public function test_delivery_orders_are_hidden_without_their_permission(): void
    {
        $this->user->revokePermissionTo('view_delivery_orders');

        $this->actingAs($this->user->fresh())
            ->getJson('/api/v1/dashboard/inventory/orders')
            ->assertOk()
            ->assertJsonPath('data.delivery_orders', null);
    }

    // ── Access ───────────────────────────────────────────────────────────────

    public function test_every_section_needs_the_dashboard_permission(): void
    {
        $this->user->revokePermissionTo('view_dashboard');
        $user = $this->user->fresh();

        foreach (['sales', 'receivables', 'payables', 'stock'] as $section) {
            $this->actingAs($user)->getJson("/api/v1/dashboard/inventory/{$section}")->assertForbidden();
        }
    }

    public function test_each_section_needs_its_own_screen_permission(): void
    {
        $this->user->syncPermissions(['view_dashboard', 'view_invoices']);
        $user = $this->user->fresh();

        $this->actingAs($user)->getJson('/api/v1/dashboard/inventory/sales')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/dashboard/inventory/receivables')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/dashboard/inventory/payables')->assertForbidden();
        $this->actingAs($user)->getJson('/api/v1/dashboard/inventory/stock')->assertForbidden();
    }

    public function test_disabled_inventory_module_blocks_the_sections(): void
    {
        app(SettingsService::class)->set('module.inventory', false);

        $this->actingAs($this->user)->getJson('/api/v1/dashboard/inventory/sales')->assertForbidden();
    }
}
