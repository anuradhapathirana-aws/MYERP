<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\DeliveryOrderData;
use Modules\Inventory\Http\Requests\StoreDeliveryOrderRequest;
use Modules\Inventory\Http\Requests\UpdateDeliveryOrderRequest;
use Modules\Inventory\Http\Resources\DeliveryOrderResource;
use Modules\Inventory\Models\DeliveryOrder;
use Modules\Inventory\Services\DeliveryOrderService;

class DeliveryOrderController extends Controller
{
    public function __construct(private readonly DeliveryOrderService $service)
    {
        $this->middleware('permission:view_delivery_orders')->only(['index', 'show']);
        $this->middleware('permission:create_delivery_orders')->only(['store', 'nextDoNo', 'fromSalesOrder']);
        $this->middleware('permission:edit_delivery_orders')->only(['update', 'updateStatus']);
        $this->middleware('permission:delete_delivery_orders')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters   = $request->only(['search', 'status', 'customer_id', 'so_id', 'date_from', 'date_to', 'invoiced']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => DeliveryOrderResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreDeliveryOrderRequest $request): JsonResponse
    {
        $do = $this->service->create(DeliveryOrderData::fromRequest($request));

        return response()->json(['data' => new DeliveryOrderResource($do)], 201);
    }

    public function show(DeliveryOrder $deliveryOrder): JsonResponse
    {
        $do = $this->service->find($deliveryOrder->id);

        return response()->json(['data' => new DeliveryOrderResource($do)]);
    }

    public function update(UpdateDeliveryOrderRequest $request, DeliveryOrder $deliveryOrder): JsonResponse
    {
        $do = $this->service->update($deliveryOrder, DeliveryOrderData::fromRequest($request));

        return response()->json(['data' => new DeliveryOrderResource($do)]);
    }

    public function destroy(DeliveryOrder $deliveryOrder): JsonResponse
    {
        $this->service->delete($deliveryOrder);

        return response()->json(null, 204);
    }

    /** PATCH /delivery-orders/{do}/status — confirmed (posts stock OUT) or cancelled */
    public function updateStatus(Request $request, DeliveryOrder $deliveryOrder): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', 'string']]);

        $do = $this->service->updateStatus($deliveryOrder, $validated['status']);

        return response()->json(['data' => new DeliveryOrderResource($do)]);
    }

    /** GET /delivery-orders/next-do-no — lock-free preview */
    public function nextDoNo(): JsonResponse
    {
        return response()->json(['data' => $this->service->nextDoNo()]);
    }

    /** GET /delivery-orders/from-so/{soId} — recall a confirmed SO's undelivered remainder */
    public function fromSalesOrder(int $soId): JsonResponse
    {
        return response()->json(['data' => $this->service->fromSalesOrder($soId)]);
    }
}
