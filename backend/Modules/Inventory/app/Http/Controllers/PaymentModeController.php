<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\PaymentModeData;
use Modules\Inventory\Http\Requests\StorePaymentModeRequest;
use Modules\Inventory\Http\Requests\UpdatePaymentModeRequest;
use Modules\Inventory\Http\Resources\PaymentModeResource;
use Modules\Inventory\Models\PaymentMode;
use Modules\Inventory\Services\PaymentModeService;

class PaymentModeController extends Controller
{
    public function __construct(private readonly PaymentModeService $service)
    {
        $this->middleware('permission:view_payment_modes')->only(['index', 'show', 'all']);
        $this->middleware('permission:create_payment_modes')->only(['store']);
        $this->middleware('permission:edit_payment_modes')->only(['update']);
        $this->middleware('permission:delete_payment_modes')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $paginator = $this->service->paginate();

        return response()->json([
            'data' => PaymentModeResource::collection($paginator->items()),
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
        return response()->json(['data' => PaymentModeResource::collection($this->service->all())]);
    }

    public function store(StorePaymentModeRequest $request): JsonResponse
    {
        $paymentMode = $this->service->create(PaymentModeData::fromStoreRequest($request));

        return response()->json(
            ['data' => (new PaymentModeResource($paymentMode))->toArray($request)],
            201,
        );
    }

    public function show(PaymentMode $paymentMode): JsonResponse
    {
        return response()->json(
            ['data' => (new PaymentModeResource($paymentMode))->toArray(request())],
        );
    }

    public function update(UpdatePaymentModeRequest $request, PaymentMode $paymentMode): JsonResponse
    {
        $paymentMode = $this->service->update($paymentMode, PaymentModeData::fromUpdateRequest($request));

        return response()->json(
            ['data' => (new PaymentModeResource($paymentMode))->toArray($request)],
        );
    }

    public function destroy(PaymentMode $paymentMode): JsonResponse
    {
        $this->service->delete($paymentMode);

        return response()->json(null, 204);
    }
}
