<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\StoreTypeData;
use Modules\Inventory\Http\Requests\StoreStoreTypeRequest;
use Modules\Inventory\Http\Requests\UpdateStoreTypeRequest;
use Modules\Inventory\Http\Resources\StoreTypeResource;
use Modules\Inventory\Models\StoreType;
use Modules\Inventory\Services\StoreTypeService;

class StoreTypeController extends Controller
{
    public function __construct(private readonly StoreTypeService $service)
    {
        $this->middleware('permission:view_store_types')->only(['index', 'show', 'all']);
        $this->middleware('permission:create_store_types')->only(['store']);
        $this->middleware('permission:edit_store_types')->only(['update']);
        $this->middleware('permission:delete_store_types')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $paginator = $this->service->paginate();

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (StoreType $st) => (new StoreTypeResource($st))->toArray(request()))
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

    public function all(): JsonResponse
    {
        return response()->json(['data' => $this->service->all()]);
    }

    public function store(StoreStoreTypeRequest $request): JsonResponse
    {
        $storeType = $this->service->create(StoreTypeData::fromStoreRequest($request));

        return response()->json(
            ['data' => (new StoreTypeResource($storeType))->toArray(request())],
            201,
        );
    }

    public function show(StoreType $storeType): JsonResponse
    {
        return response()->json(
            ['data' => (new StoreTypeResource($storeType))->toArray(request())],
        );
    }

    public function update(UpdateStoreTypeRequest $request, StoreType $storeType): JsonResponse
    {
        $storeType = $this->service->update($storeType, StoreTypeData::fromUpdateRequest($request));

        return response()->json(
            ['data' => (new StoreTypeResource($storeType))->toArray(request())],
        );
    }

    public function destroy(StoreType $storeType): JsonResponse
    {
        $this->service->delete($storeType);

        return response()->json(null, 204);
    }
}
