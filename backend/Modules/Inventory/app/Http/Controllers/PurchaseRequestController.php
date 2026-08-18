<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\PurchaseRequestData;
use Modules\Inventory\Http\Requests\StorePurchaseRequestRequest;
use Modules\Inventory\Http\Requests\UpdatePurchaseRequestRequest;
use Modules\Inventory\Http\Resources\PurchaseRequestResource;
use Modules\Inventory\Models\PurchaseRequest;
use Modules\Inventory\Services\PurchaseRequestService;

class PurchaseRequestController extends Controller
{
    public function __construct(private readonly PurchaseRequestService $service)
    {
        $this->middleware('permission:view_purchase_requests')->only(['index', 'show']);
        $this->middleware('permission:create_purchase_requests')->only(['store']);
        $this->middleware('permission:edit_purchase_requests')->only(['update']);
        $this->middleware('permission:delete_purchase_requests')->only(['destroy']);
        $this->middleware('permission:approve_purchase_requests')->only(['approve', 'reject']);
    }

    public function nextReferenceNo(): JsonResponse
    {
        return response()->json(['data' => $this->service->nextReferenceNo()]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters   = $request->only(['search', 'status', 'store_id', 'date_from', 'date_to']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => PurchaseRequestResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StorePurchaseRequestRequest $request): JsonResponse
    {
        $pr = $this->service->create(PurchaseRequestData::fromRequest($request));

        return response()->json(
            ['data' => (new PurchaseRequestResource($pr))->toArray($request)],
            201,
        );
    }

    public function show(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $pr = $this->service->find($purchaseRequest->id);

        return response()->json(
            ['data' => (new PurchaseRequestResource($pr))->toArray(request())],
        );
    }

    public function update(UpdatePurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $pr = $this->service->update($purchaseRequest, PurchaseRequestData::fromRequest($request));

        return response()->json(
            ['data' => (new PurchaseRequestResource($pr))->toArray($request)],
        );
    }

    public function destroy(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->service->delete($purchaseRequest);

        return response()->json(null, 204);
    }

    public function approve(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $pr = $this->service->approve($purchaseRequest, $request->input('remarks'));

        return response()->json(
            ['data' => (new PurchaseRequestResource($pr))->toArray($request)],
        );
    }

    public function reject(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $pr = $this->service->reject($purchaseRequest, $request->validated('reason'));

        return response()->json(
            ['data' => (new PurchaseRequestResource($pr))->toArray($request)],
        );
    }

    public function cancel(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $pr = $this->service->cancel($purchaseRequest);

        return response()->json(
            ['data' => (new PurchaseRequestResource($pr))->toArray(request())],
        );
    }
}
