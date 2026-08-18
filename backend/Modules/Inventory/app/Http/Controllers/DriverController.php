<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\DriverData;
use Modules\Inventory\Http\Requests\StoreDriverRequest;
use Modules\Inventory\Http\Requests\UpdateDriverRequest;
use Modules\Inventory\Http\Resources\DriverResource;
use Modules\Inventory\Models\Driver;
use Modules\Inventory\Services\DriverService;

class DriverController extends Controller
{
    public function __construct(private readonly DriverService $service)
    {
        $this->middleware('permission:view_drivers')->only(['index', 'show', 'all']);
        $this->middleware('permission:create_drivers')->only(['store']);
        $this->middleware('permission:edit_drivers')->only(['update']);
        $this->middleware('permission:delete_drivers')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $paginator = $this->service->paginate();

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Driver $item) => (new DriverResource($item))->toArray(request()))
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

    public function store(StoreDriverRequest $request): JsonResponse
    {
        $driver = $this->service->create(DriverData::fromRequest($request));

        return response()->json(
            ['data' => (new DriverResource($driver))->toArray(request())],
            201,
        );
    }

    public function show(Driver $driver): JsonResponse
    {
        return response()->json(
            ['data' => (new DriverResource($driver))->toArray(request())],
        );
    }

    public function update(UpdateDriverRequest $request, Driver $driver): JsonResponse
    {
        $updated = $this->service->update($driver, DriverData::fromRequest($request));

        return response()->json(
            ['data' => (new DriverResource($updated))->toArray(request())],
        );
    }

    public function destroy(Driver $driver): JsonResponse
    {
        $this->service->delete($driver);

        return response()->json(null, 204);
    }

    /** Flat list for <select> dropdowns — no pagination. */
    public function all(): JsonResponse
    {
        $items = $this->service->all()
            ->map(fn (Driver $d) => [
                'id'          => $d->id,
                'driver_code' => $d->driver_code,
                'name'        => trim("{$d->first_name} {$d->last_name}"),
            ])
            ->values()
            ->all();

        return response()->json(['data' => $items]);
    }
}
