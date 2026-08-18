<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inventory\Http\Resources\CostingExpenseTypeResource;
use Modules\Inventory\Models\CostingExpenseType;
use Modules\Inventory\Services\CostingService;

class CostingExpenseTypeController extends Controller
{
    public function __construct(private readonly CostingService $service)
    {
        $this->middleware('permission:view_costings')->only(['index']);
        $this->middleware('permission:manage_costing_expense_types')->only(['store', 'update', 'destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $costingType = $request->query('costing_type');

        $query = CostingExpenseType::orderBy('sort_order')->orderBy('name');

        if ($costingType) {
            $query->where('costing_type', $costingType);
        }

        if ($request->boolean('active_only', false)) {
            $query->where('is_active', true);
        }

        $types = $query->get();

        return response()->json([
            'data' => CostingExpenseTypeResource::collection($types),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'         => ['required', 'string', 'max:100'],
            'costing_type' => ['required', 'string', 'in:fob,cif'],
            'is_active'    => ['nullable', 'boolean'],
            'sort_order'   => ['nullable', 'integer'],
        ]);

        $type = CostingExpenseType::create($validated);

        return response()->json(
            ['data' => (new CostingExpenseTypeResource($type))->toArray($request)],
            201,
        );
    }

    public function update(Request $request, CostingExpenseType $costingExpenseType): JsonResponse
    {
        $validated = $request->validate([
            'name'         => ['sometimes', 'string', 'max:100'],
            'costing_type' => ['sometimes', 'string', 'in:fob,cif'],
            'is_active'    => ['nullable', 'boolean'],
            'sort_order'   => ['nullable', 'integer'],
        ]);

        $costingExpenseType->update($validated);

        return response()->json(
            ['data' => (new CostingExpenseTypeResource($costingExpenseType))->toArray($request)],
        );
    }

    public function destroy(CostingExpenseType $costingExpenseType): JsonResponse
    {
        $costingExpenseType->delete();

        return response()->json(null, 204);
    }
}
