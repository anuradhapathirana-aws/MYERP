<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\CategoryData;
use Modules\Inventory\Http\Requests\CategoryRequest;
use Modules\Inventory\Http\Resources\CategoryResource;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Services\CategoryService;

class CategoryController extends Controller
{
    public function __construct(private readonly CategoryService $service)
    {
        $this->middleware('permission:view_categories')->only(['index', 'show', 'all']);
        $this->middleware('permission:create_categories')->only(['store']);
        $this->middleware('permission:edit_categories')->only(['update']);
        $this->middleware('permission:delete_categories')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $filters   = request()->only(['search', 'product_service_type', 'parent_category_id', 'industry_id', 'company_id']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Category $c) => (new CategoryResource($c))->toArray(request()))
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

    public function store(CategoryRequest $request): JsonResponse
    {
        $category = $this->service->create(CategoryData::fromRequest($request));

        return response()->json(
            ['data' => (new CategoryResource($category))->toArray(request())],
            201,
        );
    }

    public function show(Category $category): JsonResponse
    {
        $category->load(['parent', 'industry', 'company']);

        return response()->json(
            ['data' => (new CategoryResource($category))->toArray(request())],
        );
    }

    public function update(CategoryRequest $request, Category $category): JsonResponse
    {
        $category = $this->service->update($category, CategoryData::fromRequest($request));

        return response()->json(
            ['data' => (new CategoryResource($category))->toArray(request())],
        );
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->service->delete($category);

        return response()->json(null, 204);
    }

    /** Flat list for the parent-category dropdown — id, category_name, parent_category_id. */
    public function all(): JsonResponse
    {
        $items = $this->service->all()
            ->map(fn (Category $c) => [
                'id'                 => $c->id,
                'category_name'      => $c->category_name,
                'parent_category_id' => $c->parent_category_id,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $items]);
    }
}
