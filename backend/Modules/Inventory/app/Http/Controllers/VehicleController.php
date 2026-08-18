<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\VehicleData;
use Modules\Inventory\Http\Requests\StoreVehicleRequest;
use Modules\Inventory\Http\Requests\UpdateVehicleRequest;
use Modules\Inventory\Http\Resources\VehicleResource;
use Modules\Inventory\Models\VehicleMaster;
use Modules\Inventory\Services\VehicleService;

class VehicleController extends Controller
{
    public function __construct(private readonly VehicleService $service)
    {
        $this->middleware('permission:view_vehicle_masters')->only(['index', 'show', 'all']);
        $this->middleware('permission:create_vehicle_masters')->only(['store']);
        $this->middleware('permission:edit_vehicle_masters')->only(['update']);
        $this->middleware('permission:delete_vehicle_masters')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $paginator = $this->service->paginate();

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (VehicleMaster $item) => (new VehicleResource($item))->toArray(request()))
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

    public function store(StoreVehicleRequest $request): JsonResponse
    {
        $vehicle = $this->service->create(VehicleData::fromRequest($request));

        return response()->json(
            ['data' => (new VehicleResource($vehicle))->toArray(request())],
            201,
        );
    }

    public function show(VehicleMaster $vehicleMaster): JsonResponse
    {
        $vehicleMaster->loadMissing('assignedDriver');

        return response()->json(
            ['data' => (new VehicleResource($vehicleMaster))->toArray(request())],
        );
    }

    public function update(UpdateVehicleRequest $request, VehicleMaster $vehicleMaster): JsonResponse
    {
        $vehicle = $this->service->update($vehicleMaster, VehicleData::fromRequest($request));

        return response()->json(
            ['data' => (new VehicleResource($vehicle))->toArray(request())],
        );
    }

    public function destroy(VehicleMaster $vehicleMaster): JsonResponse
    {
        $this->service->delete($vehicleMaster);

        return response()->json(null, 204);
    }

    /** Flat list for <select> dropdowns — no pagination. */
    public function all(): JsonResponse
    {
        $items = $this->service->all()
            ->map(fn (VehicleMaster $v) => [
                'id'                  => $v->id,
                'vehicle_code'        => $v->vehicle_code,
                'registration_number' => $v->registration_number,
                'label'               => "{$v->vehicle_code} — {$v->registration_number}" . ($v->make ? " ({$v->make} {$v->model})" : ''),
            ])
            ->values()
            ->all();

        return response()->json(['data' => $items]);
    }
}
