<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\LocationData;
use Modules\Inventory\Http\Requests\LocationRequest;
use Modules\Inventory\Http\Resources\LocationResource;
use Modules\Inventory\Models\Location;
use Modules\Inventory\Services\LocationService;

class LocationController extends Controller
{
    public function __construct(private readonly LocationService $service)
    {
        $this->middleware('permission:view_locations')->only(['index', 'show', 'all']);
        $this->middleware('permission:create_locations')->only(['store']);
        $this->middleware('permission:edit_locations')->only(['update']);
        $this->middleware('permission:delete_locations')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $filters   = request()->only(['search', 'type', 'city', 'country']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Location $l) => (new LocationResource($l))->toArray(request()))
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
        return response()->json($this->service->all());
    }

    public function store(LocationRequest $request): JsonResponse
    {
        $location = $this->service->create(LocationData::fromRequest($request));

        return response()->json(
            ['data' => (new LocationResource($location))->toArray(request())],
            201,
        );
    }

    public function show(Location $location): JsonResponse
    {
        $location->loadMissing(['company', 'industry', 'parentLocation']);

        return response()->json(
            ['data' => (new LocationResource($location))->toArray(request())],
        );
    }

    public function update(LocationRequest $request, Location $location): JsonResponse
    {
        $location = $this->service->update($location, LocationData::fromRequest($request));

        return response()->json(
            ['data' => (new LocationResource($location))->toArray(request())],
        );
    }

    public function destroy(Location $location): JsonResponse
    {
        $this->service->delete($location);

        return response()->json(null, 204);
    }
}
