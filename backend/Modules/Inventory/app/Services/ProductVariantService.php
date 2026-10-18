<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\Attribute;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductVariant;
use Modules\Inventory\Models\ProductVariantPrice;
use Modules\Inventory\Models\ProductVariantValue;

/**
 * Creates and maintains a product's variants from the Product form.
 *
 * A variant's combination is fixed at creation — Red / M never silently becomes
 * Blue / M, because stock and documents already point at it. Variants that are
 * referenced anywhere are deactivated instead of deleted.
 */
class ProductVariantService
{
    private const PRICE_FIELDS = ['selling_price', 'wholesale_price', 'min_price', 'max_price'];

    /**
     * Reconcile the product's variants with the submitted rows. Must run inside
     * the caller's transaction, after the product's attributes are synced.
     *
     * Each row: { id?, attribute_ids?: int[], sku?, barcode?, is_active?, prices?: [{ sales_channel_id, selling_price?, wholesale_price?, min_price?, max_price? }] }
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function sync(Product $product, array $rows): void
    {
        $existing = $product->variants()->with('values')->get()->keyBy('id');
        $byHash   = $existing->keyBy('combination_hash');
        $allowed  = $product->productAttributes()->pluck('attribute_id')->map('intval')->all();
        $keptIds  = [];

        foreach (array_values($rows) as $i => $row) {
            $variant = null;

            if (! empty($row['id'])) {
                $variant = $existing->get((int) $row['id']);
                if ($variant === null) {
                    throw ValidationException::withMessages([
                        "variants.{$i}.id" => 'This variant does not belong to the product.',
                    ]);
                }
            } else {
                $attributeIds = array_values(array_unique(array_map('intval', (array) ($row['attribute_ids'] ?? []))));
                $hash         = ProductVariant::hashFor($attributeIds);
                $variant      = $byHash->get($hash);

                if ($variant === null) {
                    $variant = $this->create($product, $attributeIds, $allowed, $i);
                    $byHash->put($hash, $variant);
                }
            }

            $isActive = array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true;

            if ($isActive) {
                $this->assertValuesAssigned($variant, $allowed, $i);
            }

            $sku = trim((string) ($row['sku'] ?? ''));
            $barcode = trim((string) ($row['barcode'] ?? ''));

            $variant->fill([
                'sku'       => $sku !== '' ? $this->assertSkuFree($sku, $variant->id, $i) : $variant->sku,
                'barcode'   => $barcode !== '' ? $this->assertBarcodeFree($barcode, $variant->id, $i) : null,
                'is_active' => $isActive,
            ])->save();

            $this->syncPrices($variant, (array) ($row['prices'] ?? []));
            $keptIds[] = $variant->id;
        }

        $removed = $existing->keys()->diff($keptIds)->map('intval')->values()->all();

        if ($removed !== []) {
            $inUse = ProductVariant::referencedIds($removed);

            // Referenced variants keep their history: deactivated, never deleted.
            ProductVariant::whereIn('id', $inUse)->update(['is_active' => false]);
            ProductVariant::whereIn('id', array_diff($removed, $inUse))->delete();
        }
    }

    /**
     * Ordered, de-duplicated "Red / Floral / M" for a set of attribute ids —
     * values sorted by attribute type id so every screen prints the same order.
     *
     * @param  Collection<int, Attribute>  $attributes
     */
    public function labelFor(Collection $attributes): string
    {
        return $attributes
            ->sortBy(fn (Attribute $a) => [$a->attribute_type_id, $a->id])
            ->pluck('attribute_name')
            ->implode(' / ');
    }

    /**
     * PRODUCTCODE-RED-FLORAL-M — upper-cased alphanumeric segments, made unique
     * with a -2, -3… suffix.
     *
     * @param  Collection<int, Attribute>  $attributes
     */
    public function generateSku(Product $product, Collection $attributes): string
    {
        $segments = $attributes
            ->sortBy(fn (Attribute $a) => [$a->attribute_type_id, $a->id])
            ->map(fn (Attribute $a) => Str::upper(Str::limit(preg_replace('/[^A-Za-z0-9]/', '', $a->attribute_name) ?: (string) $a->id, 6, '')))
            ->all();

        $base = Str::limit(
            implode('-', array_merge([$product->product_code ?: 'P' . $product->id], $segments ?: ['STD'])),
            72,
            '',
        );

        $sku = $base;
        $n   = 2;
        while (ProductVariant::where('sku', $sku)->exists()) {
            $sku = "{$base}-{$n}";
            $n++;
        }

        return $sku;
    }

    /** @param array<int, int> $attributeIds @param array<int, int> $allowed */
    private function create(Product $product, array $attributeIds, array $allowed, int $i): ProductVariant
    {
        if ($attributeIds === []) {
            throw ValidationException::withMessages([
                "variants.{$i}.attribute_ids" => 'Pick at least one attribute value for the variant.',
            ]);
        }

        $attributes = Attribute::whereIn('id', $attributeIds)->get();

        foreach ($attributes as $attribute) {
            if (! in_array((int) $attribute->id, $allowed, true)) {
                throw ValidationException::withMessages([
                    "variants.{$i}.attribute_ids" => "\"{$attribute->attribute_name}\" is not one of this product's attributes.",
                ]);
            }
        }

        if ($attributes->pluck('attribute_type_id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                "variants.{$i}.attribute_ids" => 'A variant can take only one value per attribute type.',
            ]);
        }

        $variant = ProductVariant::create([
            'product_id'       => $product->id,
            'sku'              => $this->generateSku($product, $attributes),
            'variant_label'    => $this->labelFor($attributes),
            'combination_hash' => ProductVariant::hashFor($attributeIds),
            'is_active'        => true,
        ]);

        $now = now();
        ProductVariantValue::insert($attributes->map(fn (Attribute $a) => [
            'variant_id'        => $variant->id,
            'attribute_type_id' => (int) $a->attribute_type_id,
            'attribute_id'      => (int) $a->id,
            'created_at'        => $now,
            'updated_at'        => $now,
        ])->values()->all());

        return $variant->load('values');
    }

    /** An active variant may only be built from values the product still offers. @param array<int, int> $allowed */
    private function assertValuesAssigned(ProductVariant $variant, array $allowed, int $i): void
    {
        $missing = $variant->values
            ->filter(fn (ProductVariantValue $v) => ! in_array((int) $v->attribute_id, $allowed, true));

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                "variants.{$i}.attribute_ids" => "Variant \"{$variant->variant_label}\" uses a value removed from the product's attributes — deactivate the variant or keep the value.",
            ]);
        }
    }

    private function assertSkuFree(string $sku, int $variantId, int $i): string
    {
        if (ProductVariant::where('sku', $sku)->where('id', '!=', $variantId)->exists()) {
            throw ValidationException::withMessages([
                "variants.{$i}.sku" => "SKU \"{$sku}\" is already used by another variant.",
            ]);
        }

        return $sku;
    }

    private function assertBarcodeFree(string $barcode, int $variantId, int $i): string
    {
        if (ProductVariant::where('barcode', $barcode)->where('id', '!=', $variantId)->exists()) {
            throw ValidationException::withMessages([
                "variants.{$i}.barcode" => "Barcode \"{$barcode}\" is already used by another variant.",
            ]);
        }

        return $barcode;
    }

    /**
     * Replace the variant's price rows. A row with every price empty is dropped —
     * the variant then sells at the product's own price for that channel.
     *
     * @param array<int, array<string, mixed>> $prices
     */
    private function syncPrices(ProductVariant $variant, array $prices): void
    {
        $keep = [];

        foreach ($prices as $price) {
            $channelId = (int) ($price['sales_channel_id'] ?? 0);
            if ($channelId <= 0) {
                continue;
            }

            $values = [];
            foreach (self::PRICE_FIELDS as $field) {
                $values[$field] = isset($price[$field]) && $price[$field] !== '' ? (float) $price[$field] : null;
            }

            if (array_filter($values, fn ($v) => $v !== null) === []) {
                continue;
            }

            ProductVariantPrice::updateOrCreate(
                ['variant_id' => $variant->id, 'sales_channel_id' => $channelId],
                $values,
            );
            $keep[] = $channelId;
        }

        ProductVariantPrice::where('variant_id', $variant->id)
            ->whereNotIn('sales_channel_id', $keep)
            ->delete();
    }
}
