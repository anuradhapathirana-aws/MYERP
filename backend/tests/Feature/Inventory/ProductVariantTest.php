<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\ProductServiceType;
use Modules\Inventory\Models\Attribute;
use Modules\Inventory\Models\AttributeType;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductAttribute;
use Modules\Inventory\Models\ProductVariant;
use Modules\Inventory\Models\SalesChannel;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Product variants: one row per combination of attribute values (Red / Floral / M)
 * of a quantity-tracked product, with its own SKU, prices and stock.
 */
class ProductVariantTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private int $supplierId;
    private SalesChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = ['view_products', 'create_products', 'edit_products', 'delete_products'];
        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo($permissions);

        $this->supplierId = DB::table('inv_supplier_masters')->insertGetId([
            'supplier_code' => 'SUP-0001',
            'supplier_name' => 'Garment Factory',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->channel = SalesChannel::create(['sales_channel_name' => 'Retail', 'type' => 'Retail']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function type(string $name): AttributeType
    {
        $category = Category::firstOrCreate(
            ['category_name' => 'Test Category'],
            ['product_service_type' => ProductServiceType::Product],
        );

        return AttributeType::firstOrCreate(
            ['category_id' => $category->id, 'attribute_type_name' => $name],
            ['product_service_type' => ProductServiceType::Product],
        );
    }

    private function value(string $type, string $name): Attribute
    {
        return Attribute::create(['attribute_type_id' => $this->type($type)->id, 'attribute_name' => $name]);
    }

    /** @param array<int, Attribute> $values @param array<int, array<string, mixed>>|null $variants */
    private function payload(Product $product, array $values, ?array $variants, string $tracking = 'quantity'): array
    {
        return array_filter([
            'name'               => $product->name,
            'display_name'       => 'T-Shirt',
            'product_type'       => 'Finished Good',
            'stock_tracking'     => $tracking,
            'supplier_ids'       => [$this->supplierId],
            'category_id'        => $product->category_id,
            'base_unit_type_id'  => $product->base_unit_type_id,
            'product_attributes' => array_map(fn (Attribute $a) => [
                'attribute_type_id' => $a->attribute_type_id,
                'attribute_id'      => $a->id,
            ], $values),
            'cost_details'       => [[
                'sales_channel_id' => $this->channel->id,
                'num_of_units'     => 1,
                'cost_price'       => 800,
                'selling_price'    => 1500,
            ]],
            'variants'           => $variants,
        ], fn ($v) => $v !== null);
    }

    private function product(): Product
    {
        return Product::factory()->quantityTracked()->create(['product_code' => 'TSH']);
    }

    // ── Product form ──────────────────────────────────────────────────────────

    public function test_variants_are_created_with_sku_label_values_and_prices(): void
    {
        $red     = $this->value('Colour', 'Red');
        $floral  = $this->value('Design', 'Floral');
        $m       = $this->value('Size', 'M');
        $product = $this->product();

        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$product->id}", $this->payload($product, [$red, $floral, $m], [[
                'attribute_ids' => [$m->id, $red->id, $floral->id],
                'prices'        => [['sales_channel_id' => $this->channel->id, 'selling_price' => 1750, 'min_price' => 1600]],
            ]]))
            ->assertOk()
            ->assertJsonPath('data.variants.0.sku', 'TSH-RED-FLORAL-M')
            ->assertJsonPath('data.variants.0.variant_label', 'Red / Floral / M')
            ->assertJsonPath('data.variants.0.is_active', true)
            ->assertJsonPath('data.variants.0.prices.0.selling_price', 1750)
            ->assertJsonPath('data.variants.0.prices.0.min_price', 1600)
            ->assertJsonCount(3, 'data.variants.0.values');

        $variant = ProductVariant::firstOrFail();
        $this->assertSame(ProductVariant::hashFor([$red->id, $floral->id, $m->id]), $variant->combination_hash);
        $this->assertSame($red->id, $variant->colourAttributeId());
    }

    public function test_same_combination_twice_is_one_variant(): void
    {
        $red     = $this->value('Colour', 'Red');
        $m       = $this->value('Size', 'M');
        $product = $this->product();

        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$product->id}", $this->payload($product, [$red, $m], [
                ['attribute_ids' => [$red->id, $m->id]],
                ['attribute_ids' => [$m->id, $red->id]],
            ]))
            ->assertOk();

        $this->assertSame(1, ProductVariant::count());
    }

    public function test_removed_variants_are_deleted_when_unused_and_deactivated_when_used(): void
    {
        $red     = $this->value('Colour', 'Red');
        $blue    = $this->value('Colour', 'Blue');
        $product = $this->product();
        $values  = [$red, $blue];

        $ids = $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$product->id}", $this->payload($product, $values, [
                ['attribute_ids' => [$red->id]],
                ['attribute_ids' => [$blue->id]],
            ]))
            ->assertOk()
            ->json('data.variants.*.id');

        [$redId, $blueId] = $ids;

        // Blue has moved stock — it may only be deactivated.
        DB::table('inv_stock_transactions')->insert([
            'transaction_date' => now(),
            'reference_type'   => 'GRN',
            'reference_id'     => 1,
            'product_id'       => $product->id,
            'attribute_id'     => $blue->id,
            'variant_id'       => $blueId,
            'qty_in'           => 5,
            'qty_out'          => 0,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.variants.0.in_use', false)
            ->assertJsonPath('data.variants.1.in_use', true);

        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$product->id}", $this->payload($product, $values, []))
            ->assertOk();

        $this->assertNull(ProductVariant::find($redId));
        $this->assertFalse(ProductVariant::findOrFail($blueId)->is_active);
    }

    public function test_omitting_variants_leaves_them_untouched(): void
    {
        $red     = $this->value('Colour', 'Red');
        $product = $this->product();

        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$product->id}", $this->payload($product, [$red], [['attribute_ids' => [$red->id]]]))
            ->assertOk();

        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$product->id}", $this->payload($product, [$red], null))
            ->assertOk()
            ->assertJsonCount(1, 'data.variants');
    }

    public function test_sku_and_barcode_are_editable_and_unique(): void
    {
        $red     = $this->value('Colour', 'Red');
        $blue    = $this->value('Colour', 'Blue');
        $product = $this->product();

        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$product->id}", $this->payload($product, [$red, $blue], [
                ['attribute_ids' => [$red->id], 'sku' => 'TS-RED', 'barcode' => '4790001'],
            ]))
            ->assertOk()
            ->assertJsonPath('data.variants.0.sku', 'TS-RED')
            ->assertJsonPath('data.variants.0.barcode', '4790001');

        $other = Product::factory()->quantityTracked()->create();
        ProductAttribute::create(['product_id' => $other->id, 'attribute_type_id' => $blue->attribute_type_id, 'attribute_id' => $blue->id]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$other->id}", $this->payload($other, [$blue], [
                ['attribute_ids' => [$blue->id], 'sku' => 'TS-RED'],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('variants.0.sku');
    }

    public function test_variant_values_must_be_product_attributes_and_one_per_type(): void
    {
        $red     = $this->value('Colour', 'Red');
        $blue    = $this->value('Colour', 'Blue');
        $product = $this->product();

        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$product->id}", $this->payload($product, [$red], [['attribute_ids' => [$blue->id]]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('variants.0.attribute_ids');

        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$product->id}", $this->payload($product, [$red, $blue], [['attribute_ids' => [$red->id, $blue->id]]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('variants.0.attribute_ids');
    }

    public function test_roll_products_cannot_have_variants(): void
    {
        $red     = $this->value('Colour', 'Red');
        $product = Product::factory()->create();

        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$product->id}", $this->payload($product, [$red], [['attribute_ids' => [$red->id]]], 'roll'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('variants');
    }

    // ── Back-fill migration ───────────────────────────────────────────────────

    private function ledger(Product $product, ?int $attributeId, float $in, float $out = 0): void
    {
        DB::table('inv_stock_transactions')->insert([
            'transaction_date' => now(),
            'reference_type'   => 'GRN',
            'reference_id'     => 1,
            'product_id'       => $product->id,
            'attribute_id'     => $attributeId,
            'qty_in'           => $in,
            'qty_out'          => $out,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    private function runBackfill(): void
    {
        $migration = require base_path('Modules/Inventory/database/migrations/2026_10_16_000003_backfill_product_variants_from_colours.php');
        $migration->up();
    }

    /** @return array<string, float> balance keyed by attribute id (or 'none') */
    private function balancesByColour(int $productId): array
    {
        return DB::table('inv_stock_transactions')
            ->where('product_id', $productId)
            ->selectRaw('attribute_id, SUM(qty_in - qty_out) as qty')
            ->groupBy('attribute_id')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) ($r->attribute_id ?? 'none') => (float) $r->qty])
            ->all();
    }

    public function test_backfill_turns_existing_colour_stock_into_variants_with_identical_balances(): void
    {
        $red   = $this->value('Colour', 'Red');
        $blue  = $this->value('Colour', 'Blue');
        $green = $this->value('Colour', 'Green');

        // Red + Blue assigned on the product; Green only ever appears on the ledger.
        $fg = Product::factory()->quantityTracked()->create(['product_code' => 'FG']);
        foreach ([$red, $blue] as $c) {
            ProductAttribute::create(['product_id' => $fg->id, 'attribute_type_id' => $c->attribute_type_id, 'attribute_id' => $c->id]);
        }
        $this->ledger($fg, $red->id, 10, 3);
        $this->ledger($fg, $blue->id, 4);
        $this->ledger($fg, $green->id, 2);
        $this->ledger($fg, null, 1);

        $roll     = Product::factory()->create();
        $this->ledger($roll, $red->id, 50);
        $noColour = Product::factory()->quantityTracked()->create();
        $this->ledger($noColour, null, 7);

        $before = $this->balancesByColour($fg->id);

        $this->runBackfill();
        $this->runBackfill(); // idempotent

        $variants = ProductVariant::where('product_id', $fg->id)->get()->keyBy('variant_label');
        $this->assertEqualsCanonicalizing(['Red', 'Blue', 'Green', 'Default'], $variants->keys()->all());
        $this->assertSame('FG-RED', $variants['Red']->sku);
        $this->assertSame('FG-STD', $variants['Default']->sku);

        // Every movement carries its variant; balances per variant equal balances per colour.
        $this->assertSame(0, DB::table('inv_stock_transactions')->where('product_id', $fg->id)->whereNull('variant_id')->count());
        $byVariant = DB::table('inv_stock_transactions')
            ->where('product_id', $fg->id)
            ->selectRaw('variant_id, SUM(qty_in - qty_out) as qty')
            ->groupBy('variant_id')
            ->pluck('qty', 'variant_id')
            ->map('floatval')
            ->all();
        $this->assertSame($before[(string) $red->id], $byVariant[$variants['Red']->id]);
        $this->assertSame($before[(string) $blue->id], $byVariant[$variants['Blue']->id]);
        $this->assertSame($before[(string) $green->id], $byVariant[$variants['Green']->id]);
        $this->assertSame($before['none'], $byVariant[$variants['Default']->id]);
        $this->assertSame($before, $this->balancesByColour($fg->id));

        // Green joined the product's attributes so the variant stays valid on the next save.
        $this->assertTrue(ProductAttribute::where('product_id', $fg->id)->where('attribute_id', $green->id)->exists());

        // Roll products and colourless products are untouched.
        $this->assertSame(0, ProductVariant::whereIn('product_id', [$roll->id, $noColour->id])->count());
        $this->assertSame(0, DB::table('inv_stock_transactions')->whereIn('product_id', [$roll->id, $noColour->id])->whereNotNull('variant_id')->count());
    }
}
