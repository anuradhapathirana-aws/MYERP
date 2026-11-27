<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\SalesOrderItem;
use Modules\Inventory\Services\InvoiceService;
use Modules\Inventory\Support\NetPricingBackfill;
use Tests\TestCase;

/**
 * The one-off switch of stored prices from After Tax to Before Tax must not change
 * what any invoice bills — and must be reversible.
 */
class NetPricingBackfillTest extends TestCase
{
    use RefreshDatabase;

    private int $channelId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channelId = (int) DB::table('inv_sales_channels')->insertGetId([
            'sales_channel_name' => 'Retail', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function backfill(): NetPricingBackfill
    {
        return app(NetPricingBackfill::class);
    }

    private function convert(): void
    {
        $b = $this->backfill();
        $b->applyPriceList($b->planPriceList());
        $b->applySalesOrderLines($b->planSalesOrderLines());
    }

    /** A confirmed costing line for a product: Before Tax 1000, After Tax 1180 (18%). */
    private function confirmedCosting(Product $product, ?int $grnItemId = null, float $before = 1000.0, float $after = 1180.0): void
    {
        static $seq = 0;
        $seq++;

        $costingId = (int) DB::table('inv_costings')->insertGetId([
            'document_no' => sprintf('CST-T-%04d', $seq), 'reference_no' => sprintf('CR-T-%04d', $seq),
            'supplier_id' => 1, 'costing_type' => 'fob', 'status' => 'confirmed', 'transaction_date' => '2026-11-01',
            'apply_vat' => 1, 'vat_pct' => 18, 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('inv_costing_items')->insert([
            'costing_id' => $costingId, 'grn_id' => $seq, 'grn_item_id' => $grnItemId ?? $seq, 'product_id' => $product->id,
            'unit_id' => $product->base_unit_type_id, 'base_unit_id' => $product->base_unit_type_id, 'quantity' => 1,
            'landed_unit_cost_base' => 800, 'before_tax_price' => $before, 'before_tax_price_base' => $before,
            'selling_price' => $after, 'selling_price_base' => $after, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function priceRow(Product $product, float $selling): int
    {
        return (int) DB::table('inv_product_sales_channels')->insertGetId([
            'product_id' => $product->id, 'sales_channel_id' => $this->channelId, 'unit_type_id' => $product->base_unit_type_id,
            'selling_price' => $selling, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A pre-switch SO line: After Tax price, no vat_pct yet. */
    private function legacySoLine(Product $product, float $price, float $qty = 10, float $discount = 0): int
    {
        static $seq = 0;
        $seq++;

        $soId = (int) DB::table('inv_sales_orders')->insertGetId([
            'so_no' => sprintf('SO-T-%04d', $seq), 'customer_id' => 1, 'sales_person_id' => 1, 'order_date' => '2026-11-01',
            'status' => 'confirmed', 'subtotal' => $qty * $price, 'grand_total' => $qty * $price * (1 - $discount / 100),
            'transport_charge' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('inv_sales_order_items')->insertGetId([
            'so_id' => $soId, 'product_id' => $product->id, 'unit_id' => $product->base_unit_type_id, 'quantity' => $qty,
            'unit_price' => $price, 'discount' => $discount, 'tax' => 0, 'line_total' => $qty * $price * (1 - $discount / 100),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_price_list_row_synced_from_a_costing_gets_its_exact_before_tax_price(): void
    {
        $costed = Product::factory()->create();
        $this->confirmedCosting($costed);
        $costedRow = $this->priceRow($costed, 1180);

        $typed    = Product::factory()->create();
        $typedRow = $this->priceRow($typed, 1000);

        $this->convert();

        $row = DB::table('inv_product_sales_channels')->find($costedRow);
        $this->assertEqualsWithDelta(1000.0, (float) $row->selling_price, 0.00000001);
        $this->assertSame('costing', $row->net_price_source);
        $this->assertEqualsWithDelta(1180.0, (float) $row->legacy_selling_price, 0.0001);

        // Typed by hand — taken to carry VAT, ÷ 1.18, flagged for the client to verify
        $row = DB::table('inv_product_sales_channels')->find($typedRow);
        $this->assertEqualsWithDelta(847.45762712, (float) $row->selling_price, 0.00000001);
        $this->assertSame('vat_stripped', $row->net_price_source);
    }

    public function test_uncosted_so_line_still_bills_the_same_money_on_both_invoice_types(): void
    {
        $product = Product::factory()->create();
        $lineId  = $this->legacySoLine($product, 1000.0, qty: 10);

        // Before: Non Tax billed 1000.00; Tax billed 1000 ÷ 1.18 = 847.46 + 18%
        $this->convert();

        $line = SalesOrderItem::findOrFail($lineId);
        // Stored at 8 dp (the model presents 4) — the tail keeps × 1.18 exact
        $this->assertEqualsWithDelta(847.45762712, (float) $line->getRawOriginal('unit_price'), 0.00000001);
        $this->assertEquals(18.0, (float) $line->vat_pct);
        $this->assertSame('vat_stripped', $line->net_price_source);

        $invoices = app(InvoiceService::class);
        $this->assertSame(1000.0, $invoices->linePricing($line, 'non_tax')['unit_price']);
        $this->assertSame(847.46, $invoices->linePricing($line, 'tax')['unit_price']);
        $this->assertEquals(18.0, $invoices->linePricing($line, 'tax')['tax']);

        // SO header: Before Tax total + VAT = the old After Tax total
        $so = DB::table('inv_sales_orders')->find($line->so_id);
        $this->assertEqualsWithDelta(10000.0, (float) $so->grand_total, 0.01);
        $this->assertEqualsWithDelta(10000.0, (float) $so->legacy_grand_total, 0.01);
    }

    public function test_costed_quantity_line_takes_the_costings_before_tax_price(): void
    {
        $product = Product::factory()->create(['stock_tracking' => 'quantity']);
        $this->confirmedCosting($product, grnItemId: 9001, before: 2351.80, after: 2775.124);
        $lineId = $this->legacySoLine($product, 2775.124, qty: 1200);

        $this->convert();

        $line = SalesOrderItem::findOrFail($lineId);
        $this->assertEqualsWithDelta(2351.80, (float) $line->getRawOriginal('unit_price'), 0.00000001);
        $this->assertSame('costing', $line->net_price_source);

        $invoices = app(InvoiceService::class);
        $this->assertSame(2775.12, $invoices->linePricing($line, 'non_tax')['unit_price']); // as before
        $this->assertSame(2351.80, $invoices->linePricing($line, 'tax')['unit_price']);     // as before
    }

    public function test_backfill_is_idempotent_and_revert_restores_everything(): void
    {
        $product = Product::factory()->create();
        $rowId   = $this->priceRow($product, 1180);
        $lineId  = $this->legacySoLine($product, 1180.0, qty: 5, discount: 10);

        $this->convert();
        $this->convert(); // a second run finds nothing left to convert

        $this->assertEqualsWithDelta(1000.0, (float) DB::table('inv_product_sales_channels')->find($rowId)->selling_price, 0.0001);
        $this->assertEqualsWithDelta(1000.0, (float) SalesOrderItem::findOrFail($lineId)->unit_price, 0.0001);

        $this->backfill()->revert();

        $this->assertEqualsWithDelta(1180.0, (float) DB::table('inv_product_sales_channels')->find($rowId)->selling_price, 0.0001);
        $line = SalesOrderItem::findOrFail($lineId);
        $this->assertEqualsWithDelta(1180.0, (float) $line->unit_price, 0.0001);
        $this->assertNull($line->vat_pct);
        $this->assertEqualsWithDelta(5310.0, (float) $line->line_total, 0.0001); // 5 × 1180 − 10%
    }
}
