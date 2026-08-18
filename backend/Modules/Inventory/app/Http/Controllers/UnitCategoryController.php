<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\UnitCategoryData;
use Modules\Inventory\Http\Requests\UnitCategoryBulkRequest;
use Modules\Inventory\Http\Requests\UnitCategoryRequest;
use Modules\Inventory\Http\Resources\UnitCategoryResource;
use Modules\Inventory\Models\UnitCategory;
use Modules\Inventory\Services\UnitCategoryService;

class UnitCategoryController extends Controller
{
    public function __construct(private readonly UnitCategoryService $service)
    {
        $this->middleware('permission:view_unit_categories')->only(['index', 'show', 'all']);
        $this->middleware('permission:create_unit_categories')->only(['store', 'bulkStore']);
        $this->middleware('permission:edit_unit_categories')->only(['update', 'setDefault', 'clearDefault']);
        $this->middleware('permission:delete_unit_categories')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $paginator = $this->service->paginate();

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (UnitCategory $item) => (new UnitCategoryResource($item))->toArray(request()))
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

    public function store(UnitCategoryRequest $request): JsonResponse
    {
        $category = $this->service->create(UnitCategoryData::fromRequest($request));

        return response()->json(
            ['data' => (new UnitCategoryResource($category))->toArray(request())],
            201,
        );
    }

    public function show(UnitCategory $unitCategory): JsonResponse
    {
        return response()->json(
            ['data' => (new UnitCategoryResource($unitCategory))->toArray(request())],
        );
    }

    public function update(UnitCategoryRequest $request, UnitCategory $unitCategory): JsonResponse
    {
        $category = $this->service->update($unitCategory, UnitCategoryData::fromRequest($request));

        return response()->json(
            ['data' => (new UnitCategoryResource($category))->toArray(request())],
        );
    }

    public function destroy(UnitCategory $unitCategory): JsonResponse
    {
        $this->service->delete($unitCategory);

        return response()->json(null, 204);
    }

    public function bulkStore(UnitCategoryBulkRequest $request): JsonResponse
    {
        $categories = $this->service->createMany(
            $request->validated('names'),
            $request->validated('description'),
        );

        return response()->json([
            'data' => array_map(
                fn (UnitCategory $cat) => (new UnitCategoryResource($cat))->toArray(request()),
                $categories,
            ),
        ], 201);
    }

    /** Mark this category as the default; clears any previous default. */
    public function setDefault(UnitCategory $unitCategory): JsonResponse
    {
        $category = $this->service->setDefault($unitCategory);

        return response()->json(['data' => (new UnitCategoryResource($category))->toArray(request())]);
    }

    /** Remove the default flag from this category. */
    public function clearDefault(UnitCategory $unitCategory): JsonResponse
    {
        $category = $this->service->clearDefault($unitCategory);

        return response()->json(['data' => (new UnitCategoryResource($category))->toArray(request())]);
    }

    /** Flat list for <select> dropdowns — no pagination, includes the default-category flag. */
    public function all(): JsonResponse
    {
        $items = $this->service->all()
            ->map(fn (UnitCategory $cat) => ['id' => $cat->id, 'name' => $cat->name, 'is_default' => $cat->is_default])
            ->values()
            ->all();

        return response()->json(['data' => $items]);
    }
}
