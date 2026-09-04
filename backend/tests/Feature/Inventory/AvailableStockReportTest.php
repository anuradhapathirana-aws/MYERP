<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Database\Factories\ProductFactory;
use Modules\Inventory\Enums\CostingStatus;
use Modules\Inventory\Enums\ProductServiceType;
use Modules\Inventory\Models\Attribute;
use Modules\Inventory\Models\AttributeType;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\SalesChannel;
use Modules\Inventory\Models\StockReferenceType;
use Modules\Inventory\Models\StockTransaction;
use Modules\Inventory\Models\UnitConversion;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The Available Stock report answers "what is on the shelf right now, by colour".
 *
 * Its whole reason for existing is that the balance cache (inv_product_location_stores)
 * is keyed product × store × location with no colour column, so it cannot answer that
 * question at all. The report therefore replays the ledger, where attribute_id lives —
 * and these tests pin the two things that replay must get right: colours never bleed
 * into each other, and Selling Price only ever appears when the admin ticked for it.
 */
class AvailableStockReportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/reports/inventory/available-stock';

    private User $user;
    private int $locationId;
    private int $otherLocationId;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::create(['name' => 'view_reports', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo('view_reports');

        $this->locationId      = $this->makeLocation('LOC-1', 'Main Location');
        $this->otherLocationId = $this->makeLocation('LOC-2', 'Branch Location');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeLocation(string $code, string $name): int
    {
        return (int) DB::table('inv_locations')->insertGetId([
            'company_id'             => 1,
            'industry_id'            => 1,
            'location_code'          => $code,
            'location_name'          => $name,
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

    private function makeColour(string $name): Attribute
    {
        // Resolved per test, never memoised in a static — RefreshDatabase drops the row
        // between tests while a static would keep pointing at its now-dead id.
        $category = Category::firstOrCreate(
            ['category_name' => 'Colour Holder'],
            ['product_service_type' => ProductServiceType::Product],
        );

        $type = AttributeType::firstOrCreate(
            ['category_id' => $category->id, 'attribute_type_name' => 'Colour'],
            ['product_service_type' => ProductServiceType::Product],
        );

        return Attribute::create(['attribute_type_id' => $type->id, 'attribute_name' => $name]);
    }

    /** Post one ledger movement — the only place per-colour stock actually lives. */
    private function move(
        Product $product,
        ?Attribute $colour,
        float $qtyIn,
        float $qtyOut = 0.0,
        ?int $locationId = null,
        string $referenceType = StockReferenceType::CODE_GRN,
    ): void {
        static $seq = 0;
        $seq++;

        StockTransaction::create([
            'transaction_date' => '2026-08-01 09:00:00',
            'reference_type'   => $referenceType,
            'reference_id'     => $seq,
            'product_id'       => $product->id,
            'attribute_id'     => $colour?->id,
            'location_id'      => $locationId ?? $this->locationId,
            'qty_in'           => $qtyIn,
            'qty_out'          => $qtyOut,
            'unit_id'          => $product->base_unit_type_id,
        ]);
    }

    private function givePriceList(Product $product, float $sellingPrice, ?int $unitTypeId = null): void
    {
        $channel = SalesChannel::firstOrCreate(['sales_channel_name' => 'Default']);

        DB::table('inv_product_sales_channels')->insert([
            'product_id'       => $product->id,
            'sales_channel_id' => $channel->id,
            'unit_type_id'     => $unitTypeId ?? $product->base_unit_type_id,
            'selling_price'    => $sellingPrice,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    /** A costing line pricing one product+colour, in the given status. */
    private function giveCosting(
        Product $product,
        ?Attribute $colour,
        float $sellingPriceBase,
        CostingStatus $status = CostingStatus::Confirmed,
    ): void {
        static $seq = 0;
        $seq++;

        $costingId = (int) DB::table('inv_costings')->insertGetId([
            'document_no'      => sprintf('CST-2026-%04d', $seq),
            'reference_no'     => sprintf('CRef-%04d', $seq),
            'supplier_id'      => 1,
            'costing_type'     => 'fob',
            'status'           => $status->value,
            'transaction_date' => '2026-08-01',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        DB::table('inv_costing_items')->insert([
            'costing_id'         => $costingId,
            'grn_id'             => $seq,
            'grn_item_id'        => $seq,
            'product_id'         => $product->id,
            'attribute_id'       => $colour?->id,
            'unit_id'            => $product->base_unit_type_id,
            'quantity'           => 1,
            'selling_price'      => $sellingPriceBase,
            'selling_price_base' => $sellingPriceBase,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchRows(array $query = []): array
    {
        $response = $this->actingAs($this->user)->getJson(self::URL . '?' . http_build_query($query));
        $response->assertOk();

        return $response->json('rows');
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function test_it_reports_the_net_ledger_balance_per_product_and_colour(): void
    {
        $product = Product::factory()->create(['name' => 'Cotton Fabric']);
        $red     = $this->makeColour('Red');
        $blue    = $this->makeColour('Blue');

        $this->move($product, $red, 100);
        $this->move($product, $red, 0, 30, null, StockReferenceType::CODE_SALES_DELIVERY);
        $this->move($product, $blue, 40);

        $rows = $this->fetchRows();

        // Ordered by product then colour: Blue before Red.
        $this->assertCount(2, $rows);
        $this->assertSame('Blue', $rows[0]['attribute_name']);
        $this->assertEqualsWithDelta(40.0, $rows[0]['available_qty'], 0.0001);
        $this->assertSame('Red', $rows[1]['attribute_name']);
        // A sale off Red must not touch Blue — the whole point of slicing by colour.
        $this->assertEqualsWithDelta(70.0, $rows[1]['available_qty'], 0.0001);
    }

    public function test_it_drops_depleted_and_negative_balances(): void
    {
        $product = Product::factory()->create(['name' => 'Sold Out Fabric']);
        $green   = $this->makeColour('Green');
        $black   = $this->makeColour('Black');
        $white   = $this->makeColour('White');

        $this->move($product, $green, 50);
        $this->move($product, $green, 0, 50, null, StockReferenceType::CODE_SALES_DELIVERY);   // exactly nil
        $this->move($product, $black, 10);
        $this->move($product, $black, 0, 15, null, StockReferenceType::CODE_SALES_DELIVERY);   // oversold
        $this->move($product, $white, 5);

        $rows = $this->fetchRows();

        $this->assertCount(1, $rows);
        $this->assertSame('White', $rows[0]['attribute_name']);
    }

    public function test_colourless_ledger_rows_group_into_their_own_row(): void
    {
        $product = Product::factory()->create(['name' => 'Plain Item']);
        $red     = $this->makeColour('Crimson');

        $this->move($product, null, 25);
        $this->move($product, $red, 15);

        $rows = $this->fetchRows();

        $this->assertCount(2, $rows);

        $colourless = collect($rows)->firstWhere('attribute_id', null);
        $this->assertNotNull($colourless, 'Rows with no colour must survive as their own line.');
        $this->assertEqualsWithDelta(25.0, $colourless['available_qty'], 0.0001);
    }

    public function test_selling_price_is_absent_unless_the_admin_asks_for_it(): void
    {
        $product = Product::factory()->create();
        $colour  = $this->makeColour('Ivory');
        $this->move($product, $colour, 10);
        $this->givePriceList($product, 500);

        $rows = $this->fetchRows();
        $this->assertArrayNotHasKey('selling_price', $rows[0]);

        $response = $this->actingAs($this->user)->getJson(self::URL . '?include_selling_price=1');
        $response->assertOk();
        $response->assertJsonPath('header.include_selling_price', true);
        $this->assertEqualsWithDelta(500.0, $response->json('rows.0.selling_price'), 0.0001);
    }

    public function test_the_confirmed_costing_price_wins_over_the_price_list_per_colour(): void
    {
        $product = Product::factory()->create(['name' => 'Costed Fabric']);
        $red     = $this->makeColour('Red');
        $blue    = $this->makeColour('Blue');

        $this->move($product, $red, 10);
        $this->move($product, $blue, 10);

        $this->givePriceList($product, 400);
        $this->giveCosting($product, $red, 950);
        // Draft costings are not a price anyone agreed to sell at.
        $this->giveCosting($product, $blue, 1200, CostingStatus::Draft);

        $rows = collect($this->fetchRows(['include_selling_price' => 1]))->keyBy('attribute_name');

        $this->assertEqualsWithDelta(950.0, $rows['Red']['selling_price'], 0.0001, 'Red is costed — the costing price applies.');
        $this->assertEqualsWithDelta(400.0, $rows['Blue']['selling_price'], 0.0001, 'Blue has only a draft costing — it falls back to the price list.');
    }

    public function test_selling_price_is_null_when_the_product_has_no_price_at_all(): void
    {
        $product = Product::factory()->create();
        $this->move($product, $this->makeColour('Unpriced'), 7);

        $rows = $this->fetchRows(['include_selling_price' => 1]);

        // Null, never 0.00 — a zero there reads as "free" to whoever prints the sheet.
        $this->assertNull($rows[0]['selling_price']);
    }

    public function test_a_price_list_row_in_another_unit_is_re_expressed_per_the_stocking_uom(): void
    {
        $baseUnit = ProductFactory::defaultUnitType();

        $coarse = DB::table('inv_unit_types')->insertGetId([
            'unit_category_id' => $baseUnit->unit_category_id,
            'name'             => 'Kilo Test Unit',
            'symbol'           => 'ktu',
            'unit_position'    => 'suffix',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        UnitConversion::create([
            'from_unit_type_id' => $coarse,
            'to_unit_type_id'   => $baseUnit->id,
            'multiplier'        => 1000,
        ]);

        $product = Product::factory()->create();
        $this->move($product, $this->makeColour('Converted'), 10);
        // 250 per coarse unit is 0.25 per base unit — quoting 250 would overcharge 1000×.
        $this->givePriceList($product, 250, (int) $coarse);

        $rows = $this->fetchRows(['include_selling_price' => 1]);

        $this->assertEqualsWithDelta(0.25, $rows[0]['selling_price'], 0.00000001);
    }

    public function test_filters_narrow_the_rows(): void
    {
        $productA = Product::factory()->create(['name' => 'Alpha Fabric']);
        $productB = Product::factory()->create(['name' => 'Beta Fabric']);
        $red      = $this->makeColour('Red');
        $blue     = $this->makeColour('Blue');

        $this->move($productA, $red, 10);
        $this->move($productA, $blue, 20);
        $this->move($productB, $red, 30, 0, $this->otherLocationId);

        $this->assertCount(2, $this->fetchRows(['product_id' => $productA->id]));
        $this->assertCount(2, $this->fetchRows(['attribute_id' => $red->id]));

        $atOther = $this->fetchRows(['location_id' => $this->otherLocationId]);
        $this->assertCount(1, $atOther);
        $this->assertSame('Beta Fabric', $atOther[0]['product_name']);

        $byCategory = $this->fetchRows(['category_id' => $productA->category_id]);
        $this->assertCount(3, $byCategory, 'Both factory products share the default test category.');
    }

    public function test_it_requires_the_view_reports_permission(): void
    {
        $stranger = User::factory()->create(['active_modules' => ['inventory']]);

        $this->actingAs($stranger)->getJson(self::URL)->assertForbidden();
    }

    public function test_the_csv_export_carries_the_price_column_only_when_asked(): void
    {
        $product = Product::factory()->create(['name' => 'Export Fabric']);
        $this->move($product, $this->makeColour('Amber'), 12);
        $this->givePriceList($product, 750);

        $plain = $this->actingAs($this->user)->get(self::URL . '/csv');
        $plain->assertOk();
        $this->assertStringNotContainsString('Selling Price', $plain->streamedContent());

        $priced = $this->actingAs($this->user)->get(self::URL . '/csv?include_selling_price=1');
        $priced->assertOk();
        $body = $priced->streamedContent();
        $this->assertStringContainsString('Selling Price', $body);
        $this->assertStringContainsString('750', $body);
    }

    /**
     * Smoke test for the print view. The Blade is the only piece a unit test cannot
     * reach by reading the JSON, and a typo in it surfaces nowhere until someone
     * clicks Print — so both column layouts (priced and not) are actually rendered.
     */
    public function test_the_pdf_renders_in_both_column_layouts(): void
    {
        $product = Product::factory()->create(['name' => 'Printed Fabric']);
        $this->move($product, $this->makeColour('Teal'), 12);
        $this->givePriceList($product, 750);

        foreach (['', '?include_selling_price=1'] as $query) {
            $response = $this->actingAs($this->user)->get(self::URL . '/pdf' . $query);

            $response->assertOk();
            $response->assertHeader('content-type', 'application/pdf');
            // Pdf::download() returns a plain response, not a streamed one.
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
    }
}
