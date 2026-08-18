<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\StoreData;
use Modules\Inventory\Http\Requests\StoreStoreRequest;
use Modules\Inventory\Http\Requests\UpdateStoreRequest;
use Modules\Inventory\Http\Resources\StoreResource;
use Modules\Inventory\Models\Store;
use Modules\Inventory\Services\StoreService;

class StoreController extends Controller
{
    public function __construct(private readonly StoreService $service)
    {
        $this->middleware('permission:view_stores')->only(['index', 'show', 'all']);
        $this->middleware('permission:create_stores')->only(['store']);
        $this->middleware('permission:edit_stores')->only(['update']);
        $this->middleware('permission:delete_stores')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $filters   = request()->only(['search', 'store_type_id', 'is_active', 'location_id']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Store $s) => (new StoreResource($s))->toArray(request()))
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

    public function store(StoreStoreRequest $request): JsonResponse
    {
        $store = $this->service->create(StoreData::fromStoreRequest($request));

        return response()->json(
            ['data' => (new StoreResource($store))->toArray(request())],
            201,
        );
    }

    public function show(Store $store): JsonResponse
    {
        $store->loadMissing(['storeType', 'location', 'parentStore']);

        return response()->json(
            ['data' => (new StoreResource($store))->toArray(request())],
        );
    }

    public function update(UpdateStoreRequest $request, Store $store): JsonResponse
    {
        $store = $this->service->update($store, StoreData::fromUpdateRequest($request));

        return response()->json(
            ['data' => (new StoreResource($store))->toArray(request())],
        );
    }

    public function destroy(Store $store): JsonResponse
    {
        $this->service->delete($store);

        return response()->json(null, 204);
    }
}
