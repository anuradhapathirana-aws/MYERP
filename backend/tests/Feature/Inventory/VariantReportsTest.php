<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Attribute;
use Modules\Inventory\Models\Costing;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductVariant;
use Modules\Inventory\Services\CostingService;
use Tests\Feature\Inventory\Concerns\VariantFixtures;
use Tests\TestCase;

/**
 * Variants in costing, stock reconciliation, reports and labels: each variant
 * is costed, counted, reported and labelled on its own.
 */
class VariantReportsTest extends TestCase
{
    use RefreshDatabase;
    use VariantFixtures;

    private Product $product;
    private ProductVariant $redM;
    private ProductVariant $redL;
    private Attribute $m;
    private int $grnId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootVariantWorld();

        $red     = $this->attrValue('Colour', 'Red');
        $this->m = $this->attrValue('Size', 'M');
        $l       = $this->attrValue('Size', 'L');
        [$this->product, [$this->redM, $this->redL]] = $this->variantProduct([[$red, $this->m], [$red, $l]]);

        $this->grnId = $this->receive([
            $this->grnLine($this->product, 6, $this->redM),
            $this->grnLine($this->product, 10, $this->redL),
        ]);
    }

    // ── Costing ──────────────────────────────────────────────────────────────

    public function test_confirmed_costing_writes_each_variants_own_price_not_the_product_price(): void
    {
        $costingId = DB::table('inv_costings')->insertGetId([
            'document_no' => 'CST-1', 'reference_no' => 'CRef-1', 'supplier_id' => $this->supplierId,
            'costing_type' => 'fob', 'status' => 'draft', 'apply_vat' => 0, 'vat_pct' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[$this->redM, 2000.0], [$this->redL, 1200.0]] as [$variant, $price]) {
            $line = DB::table('inv_goods_received_note_items')->where('grn_id', $this->grnId)->where('variant_id', $variant->id)->first();
            DB::table('inv_costing_items')->insert([
                'costing_id' => $costingId, 'grn_id' => $this->grnId, 'grn_item_id' => $line->id,
                'product_id' => $line->product_id, 'attribute_id' => $line->attribute_id, 'variant_id' => $variant->id,
                'base_unit_id' => $this->product->base_unit_type_id,
                'quantity' => $line->quantity_received, 'conversion_factor' => 1, 'base_quantity' => $line->quantity_received,
                'unit_price' => 800, 'landed_unit_cost_base' => 900, 'before_tax_price' => $price, 'before_tax_price_base' => $price,
                'selling_price' => $price, 'selling_price_base' => $price, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        app(CostingService::class)->confirm(Costing::findOrFail($costingId));

        $this->assertEquals(2000, DB::table('inv_product_variant_prices')->where('variant_id', $this->redM->id)->value('selling_price'));
        $this->assertEquals(1200, DB::table('inv_product_variant_prices')->where('variant_id', $this->redL->id)->value('selling_price'));
        // The product's own price list is untouched — other variants still sell at it.
        $this->assertEquals(1500, DB::table('inv_product_sales_channels')->where('product_id', $this->product->id)->value('selling_price'));
    }

    // ── Stock reconciliation ─────────────────────────────────────────────────

    public function test_reconciliation_counts_and_corrects_one_variant(): void
    {
        $base = [
            'product_id'  => $this->product->id,
            'store_id'    => $this->storeId,
            'location_id' => $this->locationId,
            'reason'      => 'Physical count',
            'counted_qty' => 4,
        ];

        $this->actingAs($this->user)->postJson('/api/v1/stock-reconciliations', $base)
            ->assertStatus(422)
            ->assertJsonValidationErrors('variant_id');

        $id = $this->actingAs($this->user)->postJson('/api/v1/stock-reconciliations', $base + ['variant_id' => $this->redM->id])
            ->assertCreated()
            ->assertJsonPath('data.system_qty_base', 6)
            ->assertJsonPath('data.variant_id', $this->redM->id)
            ->json('data.id');

        $this->actingAs($this->user)->postJson("/api/v1/stock-reconciliations/{$id}/submit")->assertOk();
        $this->actingAs($this->user)->postJson("/api/v1/stock-reconciliations/{$id}/approve")->assertOk();

        $this->assertSame(4.0, $this->variantBalance($this->redM));
        $this->assertSame(10.0, $this->variantBalance($this->redL));
    }

    // ── Reports ──────────────────────────────────────────────────────────────

    public function test_available_stock_lists_each_variant_and_filters_by_attribute_and_item_type(): void
    {
        $fabric = Product::factory()->create(['name' => 'Fabric Roll']);
        DB::table('inv_stock_transactions')->insert([
            'transaction_date' => now(), 'reference_type' => 'GRN', 'reference_id' => 999,
            'product_id' => $fabric->id, 'qty_in' => 50, 'qty_out' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = collect($this->actingAs($this->user)
            ->getJson('/api/v1/reports/inventory/available-stock')
            ->assertOk()->json('rows'));
        $this->assertCount(3, $rows);
        $this->assertEquals(6, $rows->firstWhere('variant_label', 'Red / M')['available_qty']);
        $this->assertEquals(10, $rows->firstWhere('variant_label', 'Red / L')['available_qty']);

        // Size = M only
        $sizeM = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/inventory/available-stock?attribute_values=' . $this->m->id)
            ->assertOk()->json('rows');
        $this->assertCount(1, $sizeM);
        $this->assertSame('Red / M', $sizeM[0]['variant_label']);

        // Item type splits finished goods from fabric rolls
        $this->assertCount(2, $this->actingAs($this->user)->getJson('/api/v1/reports/inventory/available-stock?item_type=quantity')->json('rows'));
        $this->assertCount(1, $this->actingAs($this->user)->getJson('/api/v1/reports/inventory/available-stock?item_type=roll')->json('rows'));
    }

    public function test_bin_card_narrows_to_one_variant(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/v1/reports/inventory/bin-card?product_id={$this->product->id}&variant_id={$this->redL->id}")
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.color', 'Red / L')
            ->assertJsonPath('summary.closing_balance', 10);
    }

    public function test_sales_by_item_summary_shows_the_variant_label(): void
    {
        $soId = $this->actingAs($this->user)->postJson('/api/v1/sales-orders', [
            'order_date' => '2026-10-18', 'customer_id' => $this->customer->id, 'sales_person_id' => $this->user->id,
            'status' => 'confirmed',
            'items' => [['product_id' => $this->product->id, 'variant_id' => $this->redL->id, 'unit_id' => $this->product->base_unit_type_id, 'quantity' => 3, 'unit_price' => 1500]],
        ])->assertCreated()->json('data.id');
        $soItemId = DB::table('inv_sales_order_items')->where('so_id', $soId)->value('id');
        $doId = $this->actingAs($this->user)->postJson('/api/v1/delivery-orders', [
            'so_id' => $soId, 'delivery_date' => '2026-10-18', 'store_id' => $this->storeId, 'location_id' => $this->locationId,
            'items' => [['so_item_id' => $soItemId, 'quantity' => 3]],
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->user)->patchJson("/api/v1/delivery-orders/{$doId}/status", ['status' => 'confirmed'])->assertOk();
        $invoiceId = $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-10-18', 'invoice_type' => 'non_tax'])
            ->assertCreated()->json('data.id');
        $this->actingAs($this->user)->patchJson("/api/v1/invoices/{$invoiceId}/status", ['status' => 'issued'])->assertOk();

        $items = $this->actingAs($this->user)
            ->getJson('/api/v1/reports/inventory/sales-by-item-summary?date_from=2026-10-01&date_to=2026-10-31')
            ->assertOk()
            ->json('categories.0.items');

        $this->assertSame('Red / L', $items[0]['attribute_name']);
        $this->assertEquals(3, $items[0]['quantity']);
    }

    // ── Labels ───────────────────────────────────────────────────────────────

    public function test_grn_variant_labels_list_and_print(): void
    {
        $lines = $this->actingAs($this->user)
            ->getJson("/api/v1/goods-received-notes/{$this->grnId}/variant-labels")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->json('data');
        $this->assertSame(6, $lines[0]['default_count']);

        $response = $this->actingAs($this->user)
            ->get("/api/v1/goods-received-notes/{$this->grnId}/variant-labels/pdf?counts[{$lines[0]['grn_item_id']}]=2&counts[{$lines[1]['grn_item_id']}]=0");
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $this->actingAs($this->user)
            ->getJson("/api/v1/goods-received-notes/{$this->grnId}/variant-labels/pdf?counts[{$lines[0]['grn_item_id']}]=0&counts[{$lines[1]['grn_item_id']}]=0")
            ->assertStatus(422);
    }
}
