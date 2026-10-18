<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Product;
use Tests\Feature\Inventory\Concerns\VariantFixtures;
use Tests\TestCase;

/**
 * Variants on the purchasing side: PR → PO → GRN carry the variant, the GRN
 * posts stock per variant, and the line pickers list variants with stock/price.
 */
class VariantPurchasingTest extends TestCase
{
    use RefreshDatabase;
    use VariantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootVariantWorld();
    }

    public function test_grn_posts_stock_per_variant_and_derives_colour(): void
    {
        $red = $this->attrValue('Colour', 'Red');
        $m   = $this->attrValue('Size', 'M');
        $l   = $this->attrValue('Size', 'L');
        [$product, [$redM, $redL]] = $this->variantProduct([[$red, $m], [$red, $l]]);

        // A forged colour on input is ignored — the variant decides the colour.
        $blue = $this->attrValue('Colour', 'Blue');
        $this->receive([
            $this->grnLine($product, 10, $redM, extra: ['attribute_id' => $blue->id]),
            $this->grnLine($product, 4, $redL),
        ]);

        $this->assertSame(10.0, $this->variantBalance($redM));
        $this->assertSame(4.0, $this->variantBalance($redL));
        $this->assertSame(0, DB::table('inv_stock_transactions')->where('product_id', $product->id)->where('attribute_id', '!=', $red->id)->count());
        $this->assertSame(2, DB::table('inv_goods_received_note_items')->where('attribute_id', $red->id)->whereNotNull('variant_id')->count());
    }

    public function test_grn_requires_variant_when_product_has_variants(): void
    {
        $red = $this->attrValue('Colour', 'Red');
        [$product] = $this->variantProduct([[$red]]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/goods-received-notes', $this->grnPayload([$this->grnLine($product, 5, null, extra: ['attribute_id' => $red->id])]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.variant_id');
    }

    public function test_grn_rejects_variant_of_another_product_or_inactive(): void
    {
        $red = $this->attrValue('Colour', 'Red');
        [$product, [$variant]] = $this->variantProduct([[$red]]);
        [, [$foreign]] = $this->variantProduct([[$red]]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/goods-received-notes', $this->grnPayload([$this->grnLine($product, 5, $foreign)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.variant_id');

        $variant->update(['is_active' => false]);
        $this->actingAs($this->user)
            ->postJson('/api/v1/goods-received-notes', $this->grnPayload([$this->grnLine($product, 5, $variant)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.variant_id');
    }

    public function test_products_without_variants_work_as_before(): void
    {
        $product = Product::factory()->quantityTracked()->create();

        $this->receive([$this->grnLine($product, 6, null)]);

        $this->assertSame(6.0, (float) DB::table('inv_stock_transactions')->where('product_id', $product->id)->whereNull('variant_id')->sum('qty_in'));
    }

    public function test_pr_to_po_to_grn_carries_the_variant(): void
    {
        $red = $this->attrValue('Colour', 'Red');
        $m   = $this->attrValue('Size', 'M');
        $l   = $this->attrValue('Size', 'L');
        [$product, [$redM, $redL]] = $this->variantProduct([[$red, $m], [$red, $l]]);

        $prId = $this->actingAs($this->user)
            ->postJson('/api/v1/purchase-requests', [
                'request_date' => '2026-10-17',
                'items'        => [[
                    'product_id' => $product->id,
                    'unit_id'    => $product->base_unit_type_id,
                    'variant_id' => $redM->id,
                    'quantity'   => 20,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.variant_id', $redM->id)
            ->assertJsonPath('data.items.0.attribute_id', $red->id)
            ->assertJsonPath('data.items.0.variant.name', 'Red / M')
            ->json('data.id');

        $fromPr = $this->actingAs($this->user)
            ->getJson("/api/v1/purchase-orders/from-pr/{$prId}")
            ->assertOk()
            ->json('data.items.0');
        $this->assertSame($redM->id, $fromPr['variant_id']);

        $poId = $this->actingAs($this->user)
            ->postJson('/api/v1/purchase-orders', [
                'po_no'                => 'PO-0001',
                'pr_id'                => $prId,
                'supplier_id'          => $this->supplierId,
                'store_id'             => $this->storeId,
                'location_id'          => $this->locationId,
                'order_date'           => '2026-10-17',
                'contact_person_name'  => 'Ann',
                'contact_person_phone' => '0771234567',
                'billing_address'      => 'Colombo',
                'items'                => [[
                    'product_id'       => $product->id,
                    'unit_id'          => $product->base_unit_type_id,
                    'variant_id'       => $fromPr['variant_id'],
                    'pr_item_id'       => $fromPr['pr_item_id'],
                    'quantity_ordered' => 20,
                    'unit_price'       => 800,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.variant_id', $redM->id)
            ->json('data.id');

        $poItemId = DB::table('inv_purchase_order_items')->where('po_id', $poId)->value('id');

        // PO-linked GRN line with no variant inherits the PO's; a picked one overrides it.
        $this->receive([
            $this->grnLine($product, 15, null, extra: ['po_item_id' => $poItemId]),
        ], 'SHIP-A');
        $this->assertSame(15.0, $this->variantBalance($redM));

        $this->receive([
            $this->grnLine($product, 5, $redL, extra: ['po_item_id' => $poItemId]),
        ], 'SHIP-B');
        $this->assertSame(5.0, $this->variantBalance($redL));
        $this->assertSame(15.0, $this->variantBalance($redM));
    }

    public function test_variant_picker_lists_stock_and_price_and_search_finds_sku(): void
    {
        $red  = $this->attrValue('Colour', 'Red');
        $blue = $this->attrValue('Colour', 'Blue');
        [$product, [$redV, $blueV]] = $this->variantProduct([[$red], [$blue]], [0 => 1750]);

        $this->receive([$this->grnLine($product, 8, $redV)]);

        $rows = collect($this->actingAs($this->user)
            ->getJson("/api/v1/products/{$product->id}/variants?store_id={$this->storeId}")
            ->assertOk()
            ->json('data'))->keyBy('variant_label');

        $this->assertSame(8, (int) $rows['Red']['stock']);
        $this->assertEquals(1750, $rows['Red']['selling_price']);     // own price
        $this->assertEquals(1500, $rows['Blue']['selling_price']);    // product fallback
        $this->assertSame($red->id, $rows['Red']['attribute_id']);

        $this->actingAs($this->user)
            ->getJson('/api/v1/product-variants/search?q=' . $blueV->sku)
            ->assertOk()
            ->assertJsonPath('data.0.id', $blueV->id);

        $this->actingAs($this->user)
            ->getJson("/api/v1/stock/by-variant?product_id={$product->id}")
            ->assertOk()
            ->assertJsonPath('data.0.variant_id', $redV->id)
            ->assertJsonPath('data.0.quantity', 8);
    }
}
