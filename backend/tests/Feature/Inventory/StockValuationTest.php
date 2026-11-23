<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Contracts\StockValuationSource;
use App\Services\SettingsService;
use App\Support\Stock\NullStockValuationSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockReferenceType;
use Modules\Inventory\Support\InventoryStockValuation;
use Tests\TestCase;

/**
 * Stock value as at a date, for the Profit & Loss's opening and closing stock.
 *
 * These pin the decisions behind the valuation: movements are dated by their
 * document (not by when they were confirmed), cost is the weighted average of
 * what was PAID (customer-return selling prices never leak in), and only
 * receipts up to the valuation date count.
 */
class StockValuationTest extends TestCase
{
    use RefreshDatabase;

    private int $loc1;
    private int $loc2;
    private int $otherCompanyLoc;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);

        $this->loc1            = $this->location('LOC-1', 1);
        $this->loc2            = $this->location('LOC-2', 1);
        $this->otherCompanyLoc = $this->location('LOC-3', 2);
    }

    private function valuation(): StockValuationSource
    {
        return app(StockValuationSource::class);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function location(string $code, int $companyId): int
    {
        return (int) DB::table('inv_locations')->insertGetId([
            'company_id'             => $companyId,
            'industry_id'            => 1,
            'location_code'          => $code,
            'location_name'          => $code,
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
    }

    /** A GRN dated $grnDate whose stock row was written at $confirmedAt. */
    private function grn(int $productId, float $qty, float $cost, string $grnDate, ?string $confirmedAt = null, ?int $loc = null): void
    {
        static $n = 0;
        $n++;

        $grnId = DB::table('inv_goods_received_notes')->insertGetId([
            'grn_no' => "GRN-{$n}", 'grn_date' => $grnDate, 'status' => 'confirmed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->row(StockReferenceType::CODE_GRN, $grnId, $productId, $qty, 0, $cost, $confirmedAt ?? "{$grnDate} 10:00:00", $loc);
    }

    private function delivery(int $productId, float $qty, float $sellingPrice, string $docDate, ?string $confirmedAt = null, ?int $loc = null): void
    {
        static $n = 0;
        $n++;

        $doId = DB::table('inv_delivery_orders')->insertGetId([
            'do_no' => "DO-{$n}", 'so_id' => 1, 'customer_id' => 1,
            'document_date' => $docDate, 'delivery_date' => $docDate, 'status' => 'confirmed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->row(StockReferenceType::CODE_SALES_DELIVERY, $doId, $productId, 0, $qty, $sellingPrice, $confirmedAt ?? "{$docDate} 10:00:00", $loc);
    }

    private function customerReturn(int $productId, float $qty, float $sellingPrice, string $returnDate): void
    {
        static $n = 0;
        $n++;

        $id = DB::table('inv_customer_returns')->insertGetId([
            'return_no' => "CR-{$n}", 'customer_id' => 1, 'invoice_id' => 1,
            'location_id' => $this->loc1, 'store_id' => 1, 'return_date' => $returnDate, 'status' => 'confirmed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->row(StockReferenceType::CODE_CUSTOMER_RETURN, $id, $productId, $qty, 0, $sellingPrice, "{$returnDate} 10:00:00");
    }

    private function row(string $type, int $refId, int $productId, float $in, float $out, ?float $price, string $at, ?int $loc = null): void
    {
        DB::table('inv_stock_transactions')->insert([
            'transaction_date' => $at,
            'reference_type'   => $type,
            'reference_id'     => $refId,
            'product_id'       => $productId,
            'location_id'      => $loc ?? $this->loc1,
            'store_id'         => 1,
            'qty_in'           => $in,
            'qty_out'          => $out,
            'unit_price'       => $price,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function test_inventory_binds_the_real_valuation(): void
    {
        $this->assertInstanceOf(InventoryStockValuation::class, $this->valuation());
        $this->assertTrue($this->valuation()->isAvailable());
    }

    public function test_stock_on_hand_times_average_cost(): void
    {
        $this->grn(1, 10, 100, '2026-07-10');
        $this->delivery(1, 4, 250, '2026-07-20');

        // 6 left at 100. The delivery's selling price plays no part.
        $this->assertSame(
            ['2026-07-15' => 1000.0, '2026-07-31' => 600.0],
            $this->valuation()->valuesAsAt(['2026-07-15', '2026-07-31']),
        );
    }

    public function test_movements_are_dated_by_their_document_not_by_confirmation(): void
    {
        // Live case: dated 30 Jul, confirmed 20 Aug. Purchases post on 30 Jul,
        // so the stock must be there at the end of July too.
        $this->grn(1, 10, 100, '2026-07-30', confirmedAt: '2026-08-20 09:00:00');

        // A delivery dated 31 Aug but confirmed on 2 Sep leaves in August.
        $this->delivery(1, 3, 250, '2026-08-31', confirmedAt: '2026-09-02 09:00:00');

        $this->assertSame(
            ['2026-07-29' => 0.0, '2026-07-31' => 1000.0, '2026-08-31' => 700.0],
            $this->valuation()->valuesAsAt(['2026-07-29', '2026-07-31', '2026-08-31']),
        );
    }

    public function test_the_average_is_weighted_and_only_counts_receipts_up_to_the_date(): void
    {
        $this->grn(1, 10, 100, '2026-07-10');
        $this->grn(1, 10, 200, '2026-08-10');

        $values = $this->valuation()->valuesAsAt(['2026-07-31', '2026-08-31']);

        // July knows only the first receipt; August averages both: 20 x 150.
        $this->assertSame(1000.0, $values['2026-07-31']);
        $this->assertSame(3000.0, $values['2026-08-31']);
    }

    public function test_a_customer_return_counts_as_quantity_but_not_as_cost(): void
    {
        $this->grn(1, 10, 100, '2026-07-10');
        $this->delivery(1, 5, 250, '2026-07-15');

        // The return row carries the SELLING price; averaging it in would
        // value the stock at (10x100 + 2x250)/12 = 125 instead of 100.
        $this->customerReturn(1, 2, 250, '2026-07-20');

        $this->assertSame(['2026-07-31' => 700.0], $this->valuation()->valuesAsAt(['2026-07-31']));
    }

    public function test_opening_stock_rows_are_a_cost_source(): void
    {
        $this->row(StockReferenceType::CODE_OPENING_STOCK, 1, 1, 5, 0, 80, '2026-07-09 00:00:00');
        $this->grn(1, 5, 120, '2026-07-10');

        $this->assertSame(['2026-07-31' => 1000.0], $this->valuation()->valuesAsAt(['2026-07-31']));
    }

    public function test_a_product_with_no_cost_yet_adds_nothing(): void
    {
        // A stock gain with no GRN behind it: there is no purchase cost to use.
        $this->row(StockReferenceType::CODE_STOCK_ADJUSTMENT, 1, 2, 4, 0, 0, '2026-07-10 10:00:00');
        $this->grn(1, 2, 50, '2026-07-10');

        $this->assertSame(['2026-07-31' => 100.0], $this->valuation()->valuesAsAt(['2026-07-31']));
    }

    public function test_location_and_company_scope_the_quantity_but_cost_stays_product_wide(): void
    {
        $this->grn(1, 10, 100, '2026-07-10', loc: $this->loc1);
        $this->grn(1, 10, 200, '2026-07-10', loc: $this->otherCompanyLoc);
        $this->grn(2, 1, 40, '2026-07-10', loc: $this->loc2);

        $v = $this->valuation();

        // Product 1 costs 150 everywhere (one average per product).
        $this->assertSame(['2026-07-31' => 1500.0], $v->valuesAsAt(['2026-07-31'], locationId: $this->loc1));
        $this->assertSame(['2026-07-31' => 1540.0], $v->valuesAsAt(['2026-07-31'], companyId: 1));
        $this->assertSame(['2026-07-31' => 1500.0], $v->valuesAsAt(['2026-07-31'], companyId: 2));
        $this->assertSame(['2026-07-31' => 3040.0], $v->valuesAsAt(['2026-07-31']));

        // Company access: a user limited to company 2 never sees company 1's stock.
        $this->assertSame(['2026-07-31' => 1500.0], $v->valuesAsAt(['2026-07-31'], allowedCompanyIds: [2]));
        $this->assertSame(['2026-07-31' => 0.0], $v->valuesAsAt(['2026-07-31'], companyId: 1, allowedCompanyIds: [2]));
    }

    public function test_switched_off_inventory_answers_not_available(): void
    {
        $this->grn(1, 10, 100, '2026-07-10');

        app(SettingsService::class)->set('module.inventory', false);

        $this->assertFalse($this->valuation()->isAvailable());
        $this->assertSame([], $this->valuation()->valuesAsAt(['2026-07-31']));
    }

    public function test_the_null_source_answers_not_available(): void
    {
        $null = new NullStockValuationSource();

        $this->assertFalse($null->isAvailable());
        $this->assertSame([], $null->valuesAsAt(['2026-07-31']));
        $this->assertNotSame('', $null->basis());
    }

    public function test_no_dates_means_no_query(): void
    {
        DB::enableQueryLog();

        $this->assertSame([], $this->valuation()->valuesAsAt([]));
        $this->assertSame([], array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'inv_stock_transactions')));
    }

    public function test_many_dates_take_two_queries(): void
    {
        $this->grn(1, 10, 100, '2026-07-10');

        DB::enableQueryLog();
        $this->valuation()->valuesAsAt(['2026-06-30', '2026-07-31', '2026-08-31', '2026-09-30']);

        $stockQueries = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'inv_stock_transactions'));
        $this->assertCount(2, $stockQueries);
    }
}
