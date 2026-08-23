<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\StockReconciliationData;
use Modules\Inventory\Http\Requests\StoreStockReconciliationRequest;
use Modules\Inventory\Http\Requests\UpdateStockReconciliationRequest;
use Modules\Inventory\Http\Resources\StockReconciliationResource;
use Modules\Inventory\Models\StockReconciliation;
use Modules\Inventory\Services\StockReconciliationService;

class StockReconciliationController extends Controller
{
    public function __construct(private readonly StockReconciliationService $service)
    {
        $this->middleware('permission:view_stock_reconciliations')->only(['index', 'show', 'availableRolls', 'nextReconciliationNo']);
        $this->middleware('permission:create_stock_reconciliations')->only(['store']);
        $this->middleware('permission:edit_stock_reconciliations')->only(['update']);
        $this->middleware('permission:delete_stock_reconciliations')->only(['destroy']);
        $this->middleware('permission:edit_stock_reconciliations')->only(['submit']);
        $this->middleware('permission:approve_stock_reconciliations')->only(['approve', 'reject']);
    }

    public function nextReconciliationNo(): JsonResponse
    {
        return response()->json(['data' => $this->service->nextReconciliationNo()]);
    }

    public function availableRolls(Request $request, int $productId): JsonResponse
    {
        return response()->json(['data' => $this->service->availableRolls($productId, $request->string('search')->value() ?: null)]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters   = $request->only(['search', 'status', 'product_id', 'store_id', 'source_type', 'source_id']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => StockReconciliationResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreStockReconciliationRequest $request): JsonResponse
    {
        $reconciliation = $this->service->create(StockReconciliationData::fromRequest($request));

        return response()->json(
            ['data' => (new StockReconciliationResource($reconciliation))->toArray($request)],
            201,
        );
    }

    public function show(StockReconciliation $stockReconciliation): JsonResponse
    {
        $reconciliation = $this->service->find($stockReconciliation->id);

        return response()->json(
            ['data' => (new StockReconciliationResource($reconciliation))->toArray(request())],
        );
    }

    public function update(UpdateStockReconciliationRequest $request, StockReconciliation $stockReconciliation): JsonResponse
    {
        $reconciliation = $this->service->update($stockReconciliation, StockReconciliationData::fromRequest($request));

        return response()->json(
            ['data' => (new StockReconciliationResource($reconciliation))->toArray($request)],
        );
    }

    public function destroy(StockReconciliation $stockReconciliation): JsonResponse
    {
        $this->service->delete($stockReconciliation);

        return response()->json(null, 204);
    }

    public function submit(StockReconciliation $stockReconciliation): JsonResponse
    {
        $reconciliation = $this->service->submit($stockReconciliation);

        return response()->json(
            ['data' => (new StockReconciliationResource($reconciliation))->toArray(request())],
        );
    }

    public function approve(StockReconciliation $stockReconciliation): JsonResponse
    {
        $reconciliation = $this->service->approve($stockReconciliation);

        return response()->json(
            ['data' => (new StockReconciliationResource($reconciliation))->toArray(request())],
        );
    }

    public function reject(Request $request, StockReconciliation $stockReconciliation): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $reconciliation = $this->service->reject($stockReconciliation, $request->string('reason')->value());

        return response()->json(
            ['data' => (new StockReconciliationResource($reconciliation))->toArray(request())],
        );
    }
}
