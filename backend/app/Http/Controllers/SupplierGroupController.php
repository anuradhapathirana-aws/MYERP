<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\SupplierGroupData;
use App\Http\Requests\SupplierGroupRequest;
use App\Http\Resources\SupplierGroupResource;
use App\Models\SupplierGroup;
use App\Services\SupplierGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class SupplierGroupController extends Controller
{
    public function __construct(private readonly SupplierGroupService $service)
    {
        $this->middleware('permission:view_supplier_groups')->only(['index', 'show', 'all']);
        $this->middleware('permission:create_supplier_groups')->only(['store']);
        $this->middleware('permission:edit_supplier_groups')->only(['update']);
        $this->middleware('permission:delete_supplier_groups')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $filters   = request()->only(['search', 'is_active']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => SupplierGroupResource::collection($paginator->items()),
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
        return response()->json(['data' => SupplierGroupResource::collection($this->service->all())]);
    }

    public function store(SupplierGroupRequest $request): JsonResponse
    {
        $group = $this->service->create(SupplierGroupData::fromRequest($request));

        return response()->json(['data' => (new SupplierGroupResource($group))->toArray($request)], 201);
    }

    public function show(SupplierGroup $supplierGroup): JsonResponse
    {
        return response()->json(
            ['data' => (new SupplierGroupResource($supplierGroup))->toArray(request())],
        );
    }

    public function update(SupplierGroupRequest $request, SupplierGroup $supplierGroup): JsonResponse
    {
        $group = $this->service->update($supplierGroup, SupplierGroupData::fromRequest($request));

        return response()->json(['data' => (new SupplierGroupResource($group))->toArray($request)]);
    }

    public function destroy(SupplierGroup $supplierGroup): JsonResponse
    {
        $this->service->delete($supplierGroup);

        return response()->json(null, 204);
    }
}
