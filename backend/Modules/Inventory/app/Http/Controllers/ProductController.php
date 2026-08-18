<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\ProductData;
use Modules\Inventory\Http\Requests\StoreProductRequest;
use Modules\Inventory\Http\Requests\UpdateProductRequest;
use Modules\Inventory\Http\Resources\ProductResource;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Services\ProductService;

class ProductController extends Controller
{
    public function __construct(private readonly ProductService $service)
    {
        $this->middleware('permission:view_products')->only(['index', 'show', 'all', 'nextProductCode']);
        $this->middleware('permission:create_products')->only(['store']);
        $this->middleware('permission:edit_products')->only(['update']);
        $this->middleware('permission:delete_products')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $filters   = request()->only(['search', 'product_type', 'category_id', 'tracking_type', 'supplier_id']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Product $item) => (new ProductResource($item))->toArray(request()))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->service->create(ProductData::fromRequest($request));

        return response()->json(
            ['data' => (new ProductResource($product))->toArray(request())],
            201,
        );
    }

    public function show(Product $product): JsonResponse
    {
        $product->loadMissing(['suppliers', 'salesChannels', 'category', 'location', 'productAttributes', 'locationStores']);

        return response()->json(
            ['data' => (new ProductResource($product))->toArray(request())],
        );
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product = $this->service->update($product, ProductData::fromRequest($request));

        return response()->json(
            ['data' => (new ProductResource($product))->toArray(request())],
        );
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->service->delete($product);

        return response()->json(null, 204);
    }

    /** Lightweight list for dropdowns — id, product_code, name only. */
    public function all(): JsonResponse
    {
        $products = Product::orderBy('name')
            ->get(['id', 'product_code', 'name', 'category_id', 'base_unit_type_id']);

        return response()->json(['data' => $products]);
    }

    /** Preview the next auto-generated product code (display only, non-locking). */
    public function nextProductCode(): JsonResponse
    {
        return response()->json(['data' => ['product_code' => $this->service->nextProductCode()]]);
    }
}
