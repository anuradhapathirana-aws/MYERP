<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\SalesOrderData;
use Modules\Inventory\Enums\SalesOrderSource;
use Modules\Inventory\Http\Requests\StoreSalesOrderRequest;
use Modules\Inventory\Http\Requests\UpdateSalesOrderRequest;
use Modules\Inventory\Http\Resources\SalesOrderResource;
use Modules\Inventory\Models\SalesOrder;
use Modules\Inventory\Services\SalesOrderService;

class SalesOrderController extends Controller
{
    public function __construct(private readonly SalesOrderService $service)
    {
        $this->middleware('permission:view_sales_orders')->only(['index', 'show']);
        $this->middleware('permission:create_sales_orders')->only(['store', 'nextSoNo']);
        $this->middleware('permission:create_sales_orders|edit_sales_orders')
            ->only(['scanPiece', 'productPrice', 'orderSources', 'availablePieces']);
        $this->middleware('permission:edit_sales_orders')->only(['update', 'updateStatus']);
        $this->middleware('permission:delete_sales_orders')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters   = $request->only(['search', 'status', 'customer_id', 'sales_person_id', 'date_from', 'date_to']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => SalesOrderResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreSalesOrderRequest $request): JsonResponse
    {
        $so = $this->service->create(SalesOrderData::fromRequest($request));

        return response()->json(['data' => new SalesOrderResource($so)], 201);
    }

    public function show(SalesOrder $salesOrder): JsonResponse
    {
        $so = $this->service->find($salesOrder->id);

        return response()->json(['data' => new SalesOrderResource($so)]);
    }

    public function update(UpdateSalesOrderRequest $request, SalesOrder $salesOrder): JsonResponse
    {
        $so = $this->service->update($salesOrder, SalesOrderData::fromRequest($request));

        return response()->json(['data' => new SalesOrderResource($so)]);
    }

    public function destroy(SalesOrder $salesOrder): JsonResponse
    {
        $this->service->delete($salesOrder);

        return response()->json(null, 204);
    }

    /** PATCH /sales-orders/{so}/status — change workflow status (confirmed, completed, cancelled) */
    public function updateStatus(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', 'string']]);

        $so = $this->service->updateStatus($salesOrder, $validated['status']);

        return response()->json(['data' => new SalesOrderResource($so)]);
    }

    /** GET /sales-orders/next-so-no — lock-free preview of the next SO number */
    public function nextSoNo(): JsonResponse
    {
        return response()->json(['data' => $this->service->nextSoNo()]);
    }

    /** GET /sales-orders/order-sources — order source options for the form */
    public function orderSources(): JsonResponse
    {
        return response()->json(['data' => SalesOrderSource::options()]);
    }

    /** GET /sales-orders/scan-piece/{pieceCode} — resolve a scanned piece QR */
    public function scanPiece(string $pieceCode): JsonResponse
    {
        return response()->json(['data' => $this->service->scanPiece($pieceCode)]);
    }

    /** GET /sales-orders/available-pieces/{productId} — in-stock rolls for the roll picker */
    public function availablePieces(int $productId): JsonResponse
    {
        return response()->json(['data' => $this->service->availablePieces($productId)]);
    }

    /**
     * GET /sales-orders/product-price/{productId} — price-list selling price
     * (sale-price default) + latest confirmed GRN cost (below-cost guard).
     */
    public function productPrice(int $productId): JsonResponse
    {
        $pricing = $this->service->productPricing($productId);

        return response()->json(['data' => [
            // Both per the product's stocking UOM — named here so the sales form can
            // re-express them in whatever unit the line actually sells in.
            'unit_price'        => $pricing['selling_price'],
            'cost_price'        => $pricing['cost_price'],
            'base_unit_type_id' => $pricing['base_unit_type_id'],
            'base_uom'          => $pricing['base_uom'],
        ]]);
    }
}
