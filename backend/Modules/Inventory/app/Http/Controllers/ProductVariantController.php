<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Services\VariantStockService;

/** Variant lookups for document-line pickers (PR / PO / GRN / SO / reconciliation). */
class ProductVariantController extends Controller
{
    public function __construct(private readonly VariantStockService $variants)
    {
    }

    /** GET /products/{product}/variants?store_id=&location_id=&include_inactive= */
    public function index(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'store_id'         => ['nullable', 'integer', 'exists:inv_stores,id'],
            'location_id'      => ['nullable', 'integer', 'exists:inv_locations,id'],
            'include_inactive' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'data' => $this->variants->pickerList(
                $product,
                $request->filled('store_id') ? $request->integer('store_id') : null,
                $request->filled('location_id') ? $request->integer('location_id') : null,
                $request->boolean('include_inactive'),
            ),
        ]);
    }

    /** GET /product-variants/search?q= — SKU / barcode scan, or label / product name. */
    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'max:100']]);

        return response()->json(['data' => $this->variants->search((string) $request->string('q'))]);
    }

    /** GET /stock/by-variant?product_id=&store_id=&location_id= */
    public function stock(Request $request): JsonResponse
    {
        $request->validate([
            'product_id'  => ['required', 'integer', 'exists:inv_products,id'],
            'store_id'    => ['nullable', 'integer', 'exists:inv_stores,id'],
            'location_id' => ['nullable', 'integer', 'exists:inv_locations,id'],
        ]);

        return response()->json([
            'data' => $this->variants->balances(
                $request->integer('product_id'),
                $request->filled('store_id') ? $request->integer('store_id') : null,
                $request->filled('location_id') ? $request->integer('location_id') : null,
            ),
        ]);
    }
}
