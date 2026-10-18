<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductVariant;

/**
 * Stock balance per product variant, read from the stock ledger — the variant
 * counterpart of ColourStockService (which stays for roll products and
 * colour-only callers). Every ledger row of a product with variants carries
 * variant_id (indexed by idx_stock_product_variant).
 */
class VariantStockService
{
    public function __construct(private readonly ProductPricingService $pricing)
    {
    }

    /**
     * Balance of one product + variant, optionally narrowed to a store/location.
     * Callers that must not race (DO confirm) hold the product's stock pivot row
     * lock before calling, which serialises every writer of this product.
     */
    public function available(int $productId, ?int $variantId, ?int $storeId = null, ?int $locationId = null): float
    {
        return (float) DB::table('inv_stock_transactions')
            ->where('product_id', $productId)
            ->when(
                $variantId === null,
                fn ($q) => $q->whereNull('variant_id'),
                fn ($q) => $q->where('variant_id', $variantId),
            )
            ->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId))
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->sum(DB::raw('qty_in - qty_out'));
    }

    /**
     * Every non-zero variant balance of a product, per store/location.
     *
     * @return array<int, array{variant_id:?int, sku:?string, variant_label:?string, attribute_id:?int, store_id:?int, location_id:?int, quantity:float}>
     */
    public function balances(int $productId, ?int $storeId = null, ?int $locationId = null): array
    {
        return DB::table('inv_stock_transactions as st')
            ->leftJoin('inv_product_variants as v', 'v.id', '=', 'st.variant_id')
            ->where('st.product_id', $productId)
            ->when($storeId !== null, fn ($q) => $q->where('st.store_id', $storeId))
            ->when($locationId !== null, fn ($q) => $q->where('st.location_id', $locationId))
            ->groupBy('st.variant_id', 'v.sku', 'v.variant_label', 'st.store_id', 'st.location_id')
            ->selectRaw('st.variant_id, v.sku, v.variant_label, MAX(st.attribute_id) as attribute_id, st.store_id, st.location_id, SUM(st.qty_in - st.qty_out) as quantity')
            ->get()
            ->map(fn ($r) => [
                'variant_id'    => $r->variant_id !== null ? (int) $r->variant_id : null,
                'sku'           => $r->sku,
                'variant_label' => $r->variant_label,
                'attribute_id'  => $r->attribute_id !== null ? (int) $r->attribute_id : null,
                'store_id'      => $r->store_id !== null ? (int) $r->store_id : null,
                'location_id'   => $r->location_id !== null ? (int) $r->location_id : null,
                'quantity'      => round((float) $r->quantity, 4),
            ])
            ->filter(fn (array $r) => abs($r['quantity']) > 0.00001)
            ->values()
            ->all();
    }

    /**
     * A product's variants for a document-line picker: label, SKU, colour, the
     * stock on hand (in the given store/location, or everywhere) and the
     * variant's default selling price per the stocking UOM.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pickerList(Product $product, ?int $storeId = null, ?int $locationId = null, bool $includeInactive = false): array
    {
        $variants = $product->variants()
            ->with('values.attribute.attributeType')
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->orderBy('variant_label')
            ->get();

        if ($variants->isEmpty()) {
            return [];
        }

        $stock = DB::table('inv_stock_transactions')
            ->where('product_id', $product->id)
            ->whereIn('variant_id', $variants->pluck('id'))
            ->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId))
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->groupBy('variant_id')
            ->selectRaw('variant_id, SUM(qty_in - qty_out) as qty')
            ->pluck('qty', 'variant_id');

        $prices = $this->pricing->variantSellingPrices($product->id, $variants->pluck('id')->map('intval')->all());

        return $variants->map(fn (ProductVariant $v) => [
            'id'            => $v->id,
            'product_id'    => $v->product_id,
            'sku'           => $v->sku,
            'barcode'       => $v->barcode,
            'variant_label' => $v->variant_label,
            'is_active'     => $v->is_active,
            'attribute_id'  => $v->colourAttributeId(),
            'values'        => $v->values->map(fn ($val) => [
                'attribute_type_id'   => $val->attribute_type_id,
                'attribute_type_name' => $val->attribute?->attributeType?->attribute_type_name,
                'attribute_id'        => $val->attribute_id,
                'attribute_name'      => $val->attribute?->attribute_name,
            ])->values()->all(),
            'stock'         => round((float) ($stock[$v->id] ?? 0), 4),
            'selling_price' => $prices[$v->id] ?? null,
        ])->values()->all();
    }

    /**
     * Find active variants by SKU / barcode (exact hits first) or by label /
     * product name — the scan-or-type box on document lines.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $term, int $limit = 20): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $like = '%' . addcslashes($term, '%_\\') . '%';

        return ProductVariant::query()
            ->join('inv_products as p', 'p.id', '=', 'inv_product_variants.product_id')
            ->where('inv_product_variants.is_active', true)
            ->where(fn ($q) => $q
                ->where('inv_product_variants.sku', $term)
                ->orWhere('inv_product_variants.barcode', $term)
                ->orWhere('inv_product_variants.sku', 'like', $like)
                ->orWhere('inv_product_variants.variant_label', 'like', $like)
                ->orWhere('p.name', 'like', $like)
                ->orWhere('p.product_code', 'like', $like))
            // Exact SKU / barcode hits first — a scan must land on its variant.
            ->orderByRaw('CASE WHEN inv_product_variants.sku = ? OR inv_product_variants.barcode = ? THEN 0 ELSE 1 END', [$term, $term])
            ->orderBy('p.name')
            ->orderBy('inv_product_variants.variant_label')
            ->limit($limit)
            ->get([
                'inv_product_variants.id',
                'inv_product_variants.product_id',
                'inv_product_variants.sku',
                'inv_product_variants.barcode',
                'inv_product_variants.variant_label',
                'p.product_code',
                'p.name as product_name',
            ])
            ->map(fn ($v) => [
                'id'            => $v->id,
                'product_id'    => $v->product_id,
                'product_code'  => $v->product_code,
                'product_name'  => $v->product_name,
                'sku'           => $v->sku,
                'barcode'       => $v->barcode,
                'variant_label' => $v->variant_label,
            ])
            ->all();
    }
}
