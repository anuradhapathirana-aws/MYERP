<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory\Concerns;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\ProductServiceType;
use Modules\Inventory\Models\Attribute;
use Modules\Inventory\Models\AttributeType;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductAttribute;
use Modules\Inventory\Models\ProductVariant;
use Modules\Inventory\Models\SalesChannel;
use Modules\Inventory\Services\ProductVariantService;
use Spatie\Permission\Models\Permission;

/** Shared world for variant tests: user, supplier, customer, store, location, channel. */
trait VariantFixtures
{
    protected User $user;
    protected CustomerMaster $customer;
    protected int $supplierId;
    protected int $storeId;
    protected int $locationId;
    protected SalesChannel $channel;

    protected function bootVariantWorld(): void
    {
        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [];
        foreach (['purchase_requests', 'purchase_orders', 'grns', 'sales_orders', 'delivery_orders', 'invoices', 'products', 'stock_reconciliations', 'customer_returns', 'proforma_invoices'] as $doc) {
            foreach (['view', 'create', 'edit', 'delete'] as $verb) {
                $permissions[] = "{$verb}_{$doc}";
            }
        }
        $permissions = array_merge($permissions, [
            'approve_purchase_requests', 'confirm_grns', 'approve_stock_reconciliations', 'confirm_customer_returns', 'view_reports',
        ]);
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo($permissions);

        $this->customer = CustomerMaster::create([
            'customer_code' => 'CUS-0001',
            'customer_name' => 'Garment Buyer',
            'customer_type' => 'Retail',
        ]);
        $this->supplierId = DB::table('inv_supplier_masters')->insertGetId([
            'supplier_code' => 'SUP-0001',
            'supplier_name' => 'Garment Factory',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        $this->locationId = DB::table('inv_locations')->insertGetId([
            'company_id'             => 1,
            'industry_id'            => 1,
            'location_code'          => 'LOC-1',
            'location_name'          => 'Main',
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
        $this->storeId = DB::table('inv_stores')->insertGetId([
            'store_type_id' => 1,
            'location_id'   => $this->locationId,
            'store_code'    => 'STR-1',
            'store_name'    => 'Finished Goods Store',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        $this->channel = SalesChannel::create(['sales_channel_name' => 'Retail', 'type' => 'Retail']);
    }

    protected function attrType(string $name): AttributeType
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

    protected function attrValue(string $type, string $name): Attribute
    {
        return Attribute::create(['attribute_type_id' => $this->attrType($type)->id, 'attribute_name' => $name]);
    }

    /**
     * A quantity-tracked product with a 1500 retail price and the given
     * variants, each an array of attribute values.
     *
     * @param  array<int, array<int, Attribute>>  $combos
     * @return array{0: Product, 1: array<int, ProductVariant>}
     */
    protected function variantProduct(array $combos, array $variantPrices = [], string $code = ''): array
    {
        static $n = 0;
        $product = Product::factory()->quantityTracked()->create(['product_code' => $code !== '' ? $code : 'TSH' . (++$n)]);

        DB::table('inv_product_sales_channels')->insert([
            'product_id'       => $product->id,
            'sales_channel_id' => $this->channel->id,
            'unit_type_id'     => $product->base_unit_type_id,
            'num_of_units'     => 1,
            'cost_price'       => 800,
            'selling_price'    => 1500,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $assigned = [];
        foreach ($combos as $combo) {
            foreach ($combo as $value) {
                if (! isset($assigned[$value->id])) {
                    ProductAttribute::create([
                        'product_id'        => $product->id,
                        'attribute_type_id' => $value->attribute_type_id,
                        'attribute_id'      => $value->id,
                    ]);
                    $assigned[$value->id] = true;
                }
            }
        }

        app(ProductVariantService::class)->sync($product, array_map(fn (array $combo, int $i) => [
            'attribute_ids' => array_map(fn (Attribute $a) => $a->id, $combo),
            'prices'        => isset($variantPrices[$i])
                ? [['sales_channel_id' => $this->channel->id, 'selling_price' => $variantPrices[$i]]]
                : [],
        ], $combos, array_keys($combos)));

        $variants = ProductVariant::where('product_id', $product->id)->orderBy('id')->get()->all();

        return [$product, $variants];
    }

    protected function grnPayload(array $lines, string $shippingCode = 'SHIP-001'): array
    {
        return [
            'supplier_id'   => $this->supplierId,
            'grn_date'      => '2026-10-17',
            'shipping_code' => $shippingCode,
            'store_id'      => $this->storeId,
            'location_id'   => $this->locationId,
            'items'         => $lines,
        ];
    }

    protected function grnLine(Product $product, float $qty, ?ProductVariant $variant, float $price = 800.0, array $extra = []): array
    {
        return array_filter([
            'product_id'        => $product->id,
            'variant_id'        => $variant?->id,
            'unit_id'           => $product->base_unit_type_id,
            'quantity_received' => $qty,
            'unit_price'        => $price,
        ], fn ($v) => $v !== null) + $extra;
    }

    /** Create + confirm a GRN through the API; returns its id. */
    protected function receive(array $lines, string $shippingCode = 'SHIP-001'): int
    {
        $id = $this->actingAs($this->user)
            ->postJson('/api/v1/goods-received-notes', $this->grnPayload($lines, $shippingCode))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->user)
            ->postJson("/api/v1/goods-received-notes/{$id}/confirm")
            ->assertOk();

        return (int) $id;
    }

    protected function variantBalance(ProductVariant $variant): float
    {
        return (float) DB::table('inv_stock_transactions')
            ->where('variant_id', $variant->id)
            ->sum(DB::raw('qty_in - qty_out'));
    }
}
