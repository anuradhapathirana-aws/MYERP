<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\TaxCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\GoodsReceivedNoteItem;
use Modules\Inventory\Models\Product;
use Tests\Feature\Inventory\Concerns\VariantFixtures;
use Tests\TestCase;

/**
 * GRN lines priced with a tax code: tax after the trade discount, prices
 * quoted VAT-inclusive split back to before tax, and the claimable part kept
 * apart from the part that is cost.
 */
class GrnTaxTest extends TestCase
{
    use RefreshDatabase;
    use VariantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootVariantWorld();
    }

    private function codeId(string $code): int
    {
        return (int) TaxCode::where('code', $code)->value('id');
    }

    /** @return array<string, mixed> the saved draft */
    private function draft(array $line, array $header = []): array
    {
        $product = Product::factory()->quantityTracked()->create();

        return $this->actingAs($this->user)
            ->postJson('/api/v1/goods-received-notes', $header + $this->grnPayload([
                $this->grnLine($product, $line['qty'] ?? 10, null, $line['price'] ?? 100, array_diff_key($line, ['qty' => 0, 'price' => 0])),
            ], 'SHIP-' . uniqid()))
            ->assertCreated()
            ->json('data');
    }

    public function test_vat_is_charged_after_the_trade_discount(): void
    {
        // 10 x 100 = 1000, less 5% = 950, VAT 18% = 171
        $grn = $this->draft(['discount' => 5, 'tax_code_id' => $this->codeId('VAT-IN')]);
        $line = $grn['items'][0];

        $this->assertEquals(100.0, $line['unit_price']);
        $this->assertEquals(171.0, $line['tax_amount']);
        $this->assertEquals(171.0, $line['recoverable_tax']);
        $this->assertEquals(1121.0, $line['line_total']);
        $this->assertEquals(1121.0, $grn['total_amount']);
        $this->assertSame('VAT-IN', $line['tax_code']['code']);
        $this->assertSame([['tax_code_ref' => 'VAT', 'rate' => 18, 'base' => 950, 'amount' => 171, 'is_recoverable' => true]], $line['taxes']);
    }

    public function test_a_vat_inclusive_invoice_price_is_split_and_stored_before_tax(): void
    {
        $grn = $this->draft(['price' => 118, 'tax_code_id' => $this->codeId('VAT-IN')], ['prices_include_tax' => true]);
        $line = $grn['items'][0];

        $this->assertTrue($grn['prices_include_tax']);
        $this->assertEquals(118.0, $line['unit_price_incl'], 'Shown back to the user as typed.');
        $this->assertEquals(100.0, $line['unit_price'], 'Stock, costing and the last price read the price before tax.');
        $this->assertEquals(180.0, $line['tax_amount']);
        $this->assertEquals(1180.0, $line['line_total'], 'Exactly what the supplier invoiced.');
    }

    public function test_sscl_then_vat_on_top_keeps_sscl_as_cost(): void
    {
        // 1000; SSCL_PUR 2.5% = 25; VAT 18% on 1025 = 184.50
        $grn = $this->draft(['tax_code_id' => $this->codeId('SSCL-VAT-IN')]);
        $line = $grn['items'][0];

        $this->assertEquals(209.5, $line['tax_amount']);
        $this->assertEquals(184.5, $line['recoverable_tax']);
        $this->assertEquals(1209.5, $line['line_total']);
        $this->assertEquals(20.95, $line['tax'], 'Effective % for old reports.');
        $this->assertCount(2, $line['taxes']);
    }

    /** No tax code = exactly today's behaviour, and a bare % from an old client is ignored. */
    public function test_a_line_without_a_tax_code_carries_no_tax(): void
    {
        $grn = $this->draft(['tax' => 18]);
        $line = $grn['items'][0];

        $this->assertNull($line['tax_code_id']);
        $this->assertEquals(0.0, $line['tax']);
        $this->assertEquals(0.0, $line['tax_amount']);
        $this->assertEquals(1000.0, $line['line_total']);
        $this->assertSame([], $line['taxes']);
    }

    public function test_import_and_no_tax_invoice_codes_add_nothing(): void
    {
        foreach (['IMPORT', 'NO-TAX-INV', 'EXEMPT-IN'] as $code) {
            $line = $this->draft(['tax_code_id' => $this->codeId($code)])['items'][0];

            $this->assertEquals(1000.0, $line['line_total'], $code);
            $this->assertSame($code, $line['tax_code']['code']);
        }
    }

    public function test_an_inactive_or_unknown_tax_code_is_refused(): void
    {
        TaxCode::where('code', 'EXEMPT-IN')->update(['is_active' => false]);
        $product = Product::factory()->quantityTracked()->create();

        foreach ([$this->codeId('EXEMPT-IN'), 999999] as $bad) {
            $this->actingAs($this->user)
                ->postJson('/api/v1/goods-received-notes', $this->grnPayload([
                    $this->grnLine($product, 1, null, 100, ['tax_code_id' => $bad]),
                ], 'SHIP-' . uniqid()))
                ->assertStatus(422)
                ->assertJsonValidationErrors('items.0.tax_code_id');
        }
    }

    public function test_editing_a_draft_reprices_and_replaces_its_taxes(): void
    {
        $grn = $this->draft(['tax_code_id' => $this->codeId('SSCL-VAT-IN')]);
        $item = $grn['items'][0];

        $payload = $this->grnPayload([[
            'product_id'        => $item['product_id'],
            'unit_id'           => $item['unit_id'],
            'quantity_received' => 10,
            'unit_price'        => 100,
            'tax_code_id'       => $this->codeId('VAT-IN'),
        ]], 'SHIP-' . uniqid());

        $updated = $this->actingAs($this->user)
            ->putJson("/api/v1/goods-received-notes/{$grn['id']}", $payload)
            ->assertOk()
            ->json('data');

        $this->assertEquals(1180.0, $updated['total_amount']);
        $this->assertSame(1, DB::table('inv_grn_item_taxes')->count(), 'The old SSCL + VAT rows are gone.');
    }

    /** Confirming posts stock at the price BEFORE tax, even for a VAT-inclusive invoice. */
    public function test_stock_is_valued_before_tax(): void
    {
        $product = Product::factory()->quantityTracked()->create();
        $this->actingAs($this->user)->postJson('/api/v1/goods-received-notes', $this->grnPayload([
            $this->grnLine($product, 10, null, 118, ['tax_code_id' => $this->codeId('VAT-IN')]),
        ], 'SHIP-' . uniqid()) + ['prices_include_tax' => true])->assertCreated();

        $grnId = (int) DB::table('inv_goods_received_notes')->latest('id')->value('id');
        $this->actingAs($this->user)->postJson("/api/v1/goods-received-notes/{$grnId}/confirm")->assertOk();

        $this->assertSame(100.0, (float) DB::table('inv_stock_transactions')->where('product_id', $product->id)->value('unit_price'));
    }

    /** The migration gives a line saved before tax codes exactly what the old journal claimed. */
    public function test_the_backfill_carries_an_old_tax_percent_into_the_claimable_tax(): void
    {
        $migration = require base_path('Modules/Inventory/database/migrations/2026_11_03_000001_add_tax_codes_to_inv_goods_received_notes.php');
        $migration->down();

        $grnId = DB::table('inv_goods_received_notes')->insertGetId([
            'grn_no' => 'GRN-OLD', 'supplier_id' => $this->supplierId, 'grn_date' => '2026-01-01',
            'status' => 'confirmed', 'total_amount' => 1180, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inv_goods_received_note_items')->insert([
            'grn_id' => $grnId, 'product_id' => 1, 'quantity_received' => 10, 'unit_price' => 100,
            'discount' => 0, 'tax' => 18, 'line_total' => 1180, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();

        $item = GoodsReceivedNoteItem::where('grn_id', $grnId)->sole();
        $this->assertSame(180.0, (float) $item->tax_amount);
        $this->assertSame(180.0, (float) $item->recoverable_tax);
        $this->assertNull($item->tax_code_id);
    }
}
