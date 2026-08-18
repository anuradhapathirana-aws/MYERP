<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\PurchaseOrderData;
use Modules\Inventory\Http\Requests\StorePurchaseOrderRequest;
use Modules\Inventory\Http\Requests\UpdatePurchaseOrderRequest;
use Modules\Inventory\Http\Resources\PurchaseOrderResource;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Services\PurchaseOrderService;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $service)
    {
        $this->middleware('permission:view_purchase_orders')->only(['index', 'show', 'loadFromPR']);
        $this->middleware('permission:create_purchase_orders')->only(['store']);
        $this->middleware('permission:edit_purchase_orders')->only(['update', 'updateStatus']);
        $this->middleware('permission:delete_purchase_orders')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters   = $request->only(['search', 'status', 'supplier_id', 'store_id', 'date_from', 'date_to']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => PurchaseOrderResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $po = $this->service->create(PurchaseOrderData::fromRequest($request));

        return response()->json(['data' => new PurchaseOrderResource($po)], 201);
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $po = $this->service->find($purchaseOrder->id);

        return response()->json(['data' => new PurchaseOrderResource($po)]);
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $po = $this->service->update($purchaseOrder, PurchaseOrderData::fromRequest($request));

        return response()->json(['data' => new PurchaseOrderResource($po)]);
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->service->delete($purchaseOrder);

        return response()->json(null, 204);
    }

    /** PATCH /purchase-orders/{po}/status — change workflow status (sent, confirmed, cancelled) */
    public function updateStatus(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $request->validate(['status' => ['required', 'string']]);

        $po = $this->service->updateStatus($purchaseOrder, $request->validated('status'));

        return response()->json(['data' => new PurchaseOrderResource($po)]);
    }

    /** GET /purchase-orders/next-po-no — generate next unique PO number */
    public function nextPoNo(): JsonResponse
    {
        return response()->json(['data' => $this->service->nextPoNo()]);
    }

    /** GET /purchase-orders/from-pr/{pr} — load PR items to pre-fill PO form */
    public function loadFromPR(int $prId): JsonResponse
    {
        $result = $this->service->loadFromPR($prId);

        return response()->json(['data' => $result]);
    }
}
