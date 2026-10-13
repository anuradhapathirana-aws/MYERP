<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;

/**
 * Stock balance per product + colour, read from the stock ledger.
 *
 * inv_product_location_stores.current_stock is per product only. Roll products
 * never needed more — every roll physically carries its colour — but a
 * quantity-tracked product received as 30 Red + 20 Blue is just "50" there.
 * Every ledger row carries attribute_id, so the ledger is the source of truth
 * for colour balances (indexed by idx_stock_product_colour).
 */
class ColourStockService
{
    /**
     * Balance of one product + colour, optionally narrowed to a store/location.
     * Callers that must not race (DO confirm) hold the product's stock pivot row
     * lock before calling, which serialises every writer of this product.
     */
    public function available(int $productId, ?int $attributeId, ?int $storeId = null, ?int $locationId = null): float
    {
        $query = DB::table('inv_stock_transactions')
            ->where('product_id', $productId)
            ->when(
                $attributeId === null,
                fn ($q) => $q->whereNull('attribute_id'),
                fn ($q) => $q->where('attribute_id', $attributeId),
            )
            ->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId))
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId));

        return (float) $query->sum(DB::raw('qty_in - qty_out'));
    }

    /**
     * Every non-zero colour balance of a product, per store/location — feeds the
     * SO/DO forms so users see "Red: 20, Blue: 15" before they type a quantity.
     *
     * @return array<int, array{attribute_id:?int, color:?string, store_id:?int, location_id:?int, quantity:float}>
     */
    public function balances(int $productId, ?int $storeId = null, ?int $locationId = null): array
    {
        return DB::table('inv_stock_transactions as st')
            ->leftJoin('inv_attributes as a', 'a.id', '=', 'st.attribute_id')
            ->where('st.product_id', $productId)
            ->when($storeId !== null, fn ($q) => $q->where('st.store_id', $storeId))
            ->when($locationId !== null, fn ($q) => $q->where('st.location_id', $locationId))
            ->groupBy('st.attribute_id', 'a.attribute_name', 'st.store_id', 'st.location_id')
            ->selectRaw('st.attribute_id, a.attribute_name as color, st.store_id, st.location_id, SUM(st.qty_in - st.qty_out) as quantity')
            ->get()
            ->map(fn ($r) => [
                'attribute_id' => $r->attribute_id !== null ? (int) $r->attribute_id : null,
                'color'        => $r->color,
                'store_id'     => $r->store_id !== null ? (int) $r->store_id : null,
                'location_id'  => $r->location_id !== null ? (int) $r->location_id : null,
                'quantity'     => round((float) $r->quantity, 4),
            ])
            ->filter(fn (array $r) => abs($r['quantity']) > 0.00001)
            ->values()
            ->all();
    }
}
