<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductVariant;
use Modules\Inventory\Models\SalesOrder;
use Tests\Feature\Inventory\Concerns\VariantFixtures;
use Tests\TestCase;

/**
 * Variants on the sales side: SO → DO → Invoice / Proforma → Customer Return
 * carry the exact variant, and stock is checked and moved per variant.
 */
class VariantSalesTest extends TestCase
{
    use RefreshDatabase;
    use VariantFixtures;

    private Product $product;
    private ProductVariant $redM;
    private ProductVariant $redL;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootVariantWorld();

        $red = $this->attrValue('Colour', 'Red');
        $m   = $this->attrValue('Size', 'M');
        $l   = $this->attrValue('Size', 'L');
        [$this->product, [$this->redM, $this->redL]] = $this->variantProduct([[$red, $m], [$red, $l]], [0 => 1750]);

        // Red / M: 2 in stock, Red / L: 10 — the colour Red holds 12.
        $this->receive([
            $this->grnLine($this->product, 2, $this->redM),
            $this->grnLine($this->product, 10, $this->redL),
        ]);
    }

    private function soLine(?ProductVariant $variant, float $qty, float $price = 1750.0, array $extra = []): array
    {
        return array_filter([
            'product_id' => $this->product->id,
            'variant_id' => $variant?->id,
            'unit_id'    => $this->product->base_unit_type_id,
            'quantity'   => $qty,
            'unit_price' => $price,
        ], fn ($v) => $v !== null) + $extra;
    }

    private function postSo(array $lines): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->postJson('/api/v1/sales-orders', [
            'order_date'      => '2026-10-18',
            'customer_id'     => $this->customer->id,
            'sales_person_id' => $this->user->id,
            'status'          => 'confirmed',
            'items'           => $lines,
        ]);
    }

    private function deliver(int $soId, float $qty): \Illuminate\Testing\TestResponse
    {
        $soItemId = DB::table('inv_sales_order_items')->where('so_id', $soId)->value('id');

        $doId = $this->actingAs($this->user)->postJson('/api/v1/delivery-orders', [
            'so_id'         => $soId,
            'delivery_date' => '2026-10-18',
            'store_id'      => $this->storeId,
            'location_id'   => $this->locationId,
            'items'         => [['so_item_id' => $soItemId, 'quantity' => $qty]],
        ])->assertCreated()->json('data.id');

        return $this->actingAs($this->user)
            ->patchJson("/api/v1/delivery-orders/{$doId}/status", ['status' => 'confirmed']);
    }

    // ── Sales order ──────────────────────────────────────────────────────────

    public function test_so_requires_the_variant(): void
    {
        $this->postSo([$this->soLine(null, 1)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.variant_id');
    }

    public function test_so_checks_stock_of_the_variant_itself(): void
    {
        [$product, [$blueS]] = $this->variantProduct([[$this->attrValue('Colour', 'Blue')]]);

        $this->actingAs($this->user)->postJson('/api/v1/sales-orders', [
            'order_date'      => '2026-10-18',
            'customer_id'     => $this->customer->id,
            'sales_person_id' => $this->user->id,
            'items'           => [[
                'product_id' => $product->id, 'variant_id' => $blueS->id,
                'unit_id' => $product->base_unit_type_id, 'quantity' => 1, 'unit_price' => 1500,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.variant_id');

        $this->postSo([$this->soLine($this->redM, 1)])
            ->assertCreated()
            ->assertJsonPath('data.items.0.variant_id', $this->redM->id)
            ->assertJsonPath('data.items.0.variant.name', 'Red / M');
    }

    // ── Delivery order ───────────────────────────────────────────────────────

    public function test_do_blocks_variant_shortfall_even_when_the_colour_has_enough(): void
    {
        // Red holds 12 in total, but Red / M only 2.
        $soId = $this->postSo([$this->soLine($this->redM, 5)])->assertCreated()->json('data.id');

        $this->deliver((int) $soId, 5)
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Insufficient stock for ' . $this->product->name . ' (Red / M): available 2.0000 tu, required 5.0000 tu.']);

        $this->assertSame(2.0, $this->variantBalance($this->redM));
    }

    public function test_do_ships_the_variant_and_decrements_its_balance(): void
    {
        $soId = $this->postSo([$this->soLine($this->redL, 4)])->assertCreated()->json('data.id');

        $this->deliver((int) $soId, 4)->assertOk();

        $this->assertSame(6.0, $this->variantBalance($this->redL));
        $this->assertSame(2.0, $this->variantBalance($this->redM));
        $this->assertSame(1, DB::table('inv_delivery_order_items')->where('variant_id', $this->redL->id)->count());
    }

    // ── Invoice + customer return ────────────────────────────────────────────

    public function test_invoice_and_return_carry_the_variant_and_restock_it(): void
    {
        $soId = $this->postSo([$this->soLine($this->redL, 4)])->assertCreated()->json('data.id');
        $this->deliver((int) $soId, 4)->assertOk();
        $doId = DB::table('inv_delivery_orders')->where('so_id', $soId)->value('id');

        $invoice = $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-10-18', 'invoice_type' => 'non_tax'])
            ->assertCreated()
            ->assertJsonPath('data.items.0.variant_id', $this->redL->id)
            ->json('data');
        $this->actingAs($this->user)->patchJson("/api/v1/invoices/{$invoice['id']}/status", ['status' => 'issued'])->assertOk();

        $returnId =$this->actingAs($this->user)->postJson('/api/v1/customer-returns', [
            'return_date' => '2026-10-18',
            'customer_id' => $this->customer->id,
            'invoice_id'  => $invoice['id'],
            'store_id'    => $this->storeId,
            'items'       => [[
                'invoice_item_id' => $invoice['items'][0]['id'],
                'quantity'        => 1,
                'reason'          => 'damaged',
                'condition'       => 'good',
            ]],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->user)->postJson("/api/v1/customer-returns/{$returnId}/confirm")->assertOk();

        $this->assertSame(7.0, $this->variantBalance($this->redL));
        $this->assertSame(1, DB::table('inv_customer_return_items')->where('variant_id', $this->redL->id)->count());
    }

    public function test_tax_invoice_uses_the_variants_own_costing_price(): void
    {
        $grnId = (int) DB::table('inv_goods_received_notes')->value('id');

        foreach ([[$this->redM, 2000.0, 2360.0], [$this->redL, 1000.0, 1180.0]] as [$variant, $beforeTax, $afterTax]) {
            $line = DB::table('inv_goods_received_note_items')->where('grn_id', $grnId)->where('variant_id', $variant->id)->first();
            $costingId = DB::table('inv_costings')->insertGetId([
                'document_no' => 'CST-' . $line->id, 'reference_no' => 'CRef-' . $line->id,
                'supplier_id' => $this->supplierId, 'costing_type' => 'fob', 'status' => 'confirmed',
                'apply_vat' => 1, 'vat_pct' => 18, 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('inv_costing_items')->insert([
                'costing_id' => $costingId, 'grn_id' => $grnId, 'grn_item_id' => $line->id,
                'product_id' => $line->product_id, 'attribute_id' => $line->attribute_id, 'variant_id' => $variant->id,
                'quantity' => $line->quantity_received, 'conversion_factor' => 1, 'base_quantity' => $line->quantity_received,
                'unit_price' => $line->unit_price, 'before_tax_price' => $beforeTax, 'before_tax_price_base' => $beforeTax,
                'selling_price' => $afterTax, 'selling_price_base' => $afterTax, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Same colour, different variants: each line bills its own variant's costing.
        $soId = $this->postSo([$this->soLine($this->redL, 2, 1180)])->assertCreated()->json('data.id');
        $this->deliver((int) $soId, 2)->assertOk();
        $doId = DB::table('inv_delivery_orders')->where('so_id', $soId)->value('id');

        $line = $this->actingAs($this->user)
            ->postJson('/api/v1/invoices', ['do_id' => $doId, 'invoice_date' => '2026-10-18', 'invoice_type' => 'tax'])
            ->assertCreated()
            ->json('data.items.0');

        $this->assertEquals(1000, $line['unit_price']);
        $this->assertEquals(18, $line['tax']);
    }

    // ── Proforma ─────────────────────────────────────────────────────────────

    private function proformaLine(?ProductVariant $variant, float $qty, float $price): array
    {
        return array_filter([
            'product_id' => $this->product->id,
            'variant_id' => $variant?->id,
            'unit_id'    => $this->product->base_unit_type_id,
            'quantity'   => $qty,
            'unit_price' => $price,
        ], fn ($v) => $v !== null);
    }

    public function test_proforma_requires_variant_and_recall_carries_it_into_the_so(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/proforma-invoices', [
            'customer_id' => $this->customer->id, 'proforma_date' => '2026-10-18', 'invoice_type' => 'non_tax',
            'items'       => [$this->proformaLine(null, 1, 1750)],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.variant_id');

        $pfId = $this->actingAs($this->user)->postJson('/api/v1/proforma-invoices', [
            'customer_id' => $this->customer->id, 'proforma_date' => '2026-10-18', 'invoice_type' => 'non_tax',
            'items'       => [$this->proformaLine($this->redM, 2, 1750)],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->user)->patchJson("/api/v1/proforma-invoices/{$pfId}/status", ['status' => 'issued'])->assertOk();

        $recall = $this->actingAs($this->user)->getJson("/api/v1/proforma-invoices/{$pfId}/recall")
            ->assertOk()
            ->assertJsonPath('data.lines.0.variant_id', $this->redM->id)
            ->assertJsonPath('data.lines.0.stock_status', 'in_stock')
            ->json('data.lines.0');

        // A different variant on the recalled line is refused…
        $this->postSo([$this->soLine($this->redL, 2, 1750, ['proforma_item_id' => $recall['proforma_item_id']])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.variant_id');

        // …and a line that sends none inherits the proforma's.
        $soId = $this->postSo([$this->soLine(null, 2, 1750, ['proforma_item_id' => $recall['proforma_item_id']])])
            ->assertCreated()
            ->json('data.id');
        $this->assertSame($this->redM->id, (int) SalesOrder::findOrFail($soId)->items()->value('variant_id'));
    }

    public function test_proforma_price_preview_and_stock_check_are_per_variant(): void
    {
        $preview = $this->actingAs($this->user)->postJson('/api/v1/proforma-invoices/price-preview', ['lines' => [
            ['product_id' => $this->product->id, 'variant_id' => $this->redM->id],
            ['product_id' => $this->product->id, 'variant_id' => $this->redL->id],
        ]])->assertOk()->json('data');

        $this->assertEquals(1750, $preview[0]['unit_price']); // own variant price
        $this->assertEquals(1500, $preview[1]['unit_price']); // product price fallback

        $stock = $this->actingAs($this->user)->postJson('/api/v1/proforma-invoices/stock-check', ['lines' => [
            ['product_id' => $this->product->id, 'variant_id' => $this->redM->id, 'quantity' => 5],
        ]])->assertOk()->json('data.0');

        $this->assertEquals(2, $stock['available_qty']);
        $this->assertSame('partial', $stock['stock_status']);
    }
}
