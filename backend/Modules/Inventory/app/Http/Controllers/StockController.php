<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inventory\Models\ProductLocationStore;

class StockController extends Controller
{
    /**
     * GET /stock/product?product_id=&store_id=
     * Returns current stock for a product, optionally filtered by store.
     * Used by the PR / PO form to show "Stock in Hand" per line item.
     */
    public function productStock(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => ['required', 'integer', 'exists:inv_products,id'],
            'store_id'   => ['nullable', 'integer', 'exists:inv_stores,id'],
        ]);

        $query = ProductLocationStore::where('product_id', $request->integer('product_id'));

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->integer('store_id'));
        }

        $rows = $query->get(['store_id', 'location_id', 'current_stock']);

        $totalStock = $rows->sum(fn ($r) => (float) $r->current_stock);

        return response()->json([
            'data' => [
                'product_id'  => $request->integer('product_id'),
                'total_stock' => $totalStock,
                'breakdown'   => $rows->values(),
            ],
        ]);
    }

    /**
     * GET /stock/by-store?store_id=
     * Returns aggregated stock for all products in a specific store.
     */
    public function byStore(Request $request): JsonResponse
    {
        $request->validate([
            'store_id' => ['required', 'integer', 'exists:inv_stores,id'],
        ]);

        $rows = ProductLocationStore::where('store_id', $request->integer('store_id'))
            ->with(['product:id,product_code,product_name', 'location:id,location_name'])
            ->get();

        return response()->json(['data' => $rows->values()]);
    }
}
