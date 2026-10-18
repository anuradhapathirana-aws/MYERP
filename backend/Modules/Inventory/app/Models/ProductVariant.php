<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * One sellable combination of attribute values (Red / Floral / M) of a
 * quantity-tracked product. Stock, prices, landed cost and document lines
 * are all keyed by it.
 */
class ProductVariant extends Model
{
    protected $table = 'inv_product_variants';

    /**
     * Every table that references a variant — a variant found in any of them
     * is "in use" and can only be deactivated, never deleted.
     */
    public const REFERENCING_TABLES = [
        'inv_stock_transactions',
        'inv_purchase_request_items',
        'inv_purchase_order_items',
        'inv_goods_received_note_items',
        'inv_sales_order_items',
        'inv_delivery_order_items',
        'inv_invoice_items',
        'inv_proforma_invoice_items',
        'inv_customer_return_items',
        'inv_costing_items',
        'inv_stock_reconciliations',
    ];

    protected $fillable = [
        'product_id',
        'sku',
        'barcode',
        'variant_label',
        'combination_hash',
        'is_active',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'is_active'  => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(ProductVariantValue::class, 'variant_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductVariantPrice::class, 'variant_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Sorted attribute ids joined by '-' — '' is the Default (no-value) variant. */
    public static function hashFor(array $attributeIds): string
    {
        $ids = array_values(array_unique(array_map('intval', $attributeIds)));
        sort($ids);

        return implode('-', $ids);
    }

    /**
     * Of the given variant ids, the ones referenced by stock or any document.
     *
     * @param  array<int, int>  $variantIds
     * @return array<int, int>
     */
    public static function referencedIds(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $found = [];
        foreach (self::REFERENCING_TABLES as $table) {
            $remaining = array_values(array_diff($variantIds, $found));
            if ($remaining === []) {
                break;
            }

            $found = array_merge($found, DB::table($table)
                ->whereIn('variant_id', $remaining)
                ->distinct()
                ->pluck('variant_id')
                ->map('intval')
                ->all());
        }

        return array_values(array_unique($found));
    }

    /**
     * Of the given product ids, those with at least one active variant — their
     * document lines must name a variant.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, int>
     */
    public static function productIdsWithVariants(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return static::whereIn('product_id', $productIds)
            ->where('is_active', true)
            ->distinct()
            ->pluck('product_id')
            ->map('intval')
            ->all();
    }

    /** The Colour value of this variant, mirrored onto every line's attribute_id. */
    public function colourAttributeId(): ?int
    {
        $colourTypeIds = AttributeType::colourTypeIds();

        $value = $this->relationLoaded('values')
            ? $this->values->first(fn ($v) => in_array((int) $v->attribute_type_id, $colourTypeIds, true))
            : $this->values()->whereIn('attribute_type_id', $colourTypeIds)->first();

        return $value !== null ? (int) $value->attribute_id : null;
    }
}
