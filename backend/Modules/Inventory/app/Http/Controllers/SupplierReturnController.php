<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\SupplierReturnData;
use Modules\Inventory\Http\Requests\StoreSupplierReturnRequest;
use Modules\Inventory\Http\Requests\UpdateSupplierReturnRequest;
use Modules\Inventory\Http\Resources\SupplierReturnResource;
use Modules\Inventory\Models\SupplierReturn;
use Modules\Inventory\Services\SupplierReturnService;

class SupplierReturnController extends Controller
{
    public function __construct(private readonly SupplierReturnService $service)
    {
        $this->middleware('permission:view_supplier_returns')->only(['index', 'show', 'nextReturnNo', 'returnableGrns', 'returnableItems']);
        $this->middleware('permission:create_supplier_returns')->only(['store']);
        $this->middleware('permission:edit_supplier_returns')->only(['update']);
        $this->middleware('permission:confirm_supplier_returns')->only(['confirm']);
        $this->middleware('permission:delete_supplier_returns')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters   = $request->only(['search', 'status', 'supplier_id', 'grn_id', 'date_from', 'date_to']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => SupplierReturnResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreSupplierReturnRequest $request): JsonResponse
    {
        $return = $this->service->create(SupplierReturnData::fromRequest($request));

        return response()->json(
            ['data' => (new SupplierReturnResource($return))->toArray($request)],
            201,
        );
    }

    public function show(SupplierReturn $supplierReturn): JsonResponse
    {
        $return = $this->service->find($supplierReturn->id);

        return response()->json(
            ['data' => (new SupplierReturnResource($return))->toArray(request())],
        );
    }

    public function update(UpdateSupplierReturnRequest $request, SupplierReturn $supplierReturn): JsonResponse
    {
        $return = $this->service->update($supplierReturn, SupplierReturnData::fromRequest($request));

        return response()->json(
            ['data' => (new SupplierReturnResource($return))->toArray($request)],
        );
    }

    public function destroy(SupplierReturn $supplierReturn): JsonResponse
    {
        $this->service->delete($supplierReturn);

        return response()->json(null, 204);
    }

    /** POST /supplier-returns/{return}/confirm */
    public function confirm(SupplierReturn $supplierReturn): JsonResponse
    {
        $return = $this->service->confirm($supplierReturn);

        return response()->json(
            ['data' => (new SupplierReturnResource($return))->toArray(request())],
        );
    }

    /** GET /supplier-returns/next-return-no — lock-free preview of the next return number */
    public function nextReturnNo(): JsonResponse
    {
        return response()->json(['data' => ['return_no' => $this->service->nextReturnNo()]]);
    }

    /** GET /supplier-returns/returnable-grns/{supplierId} */
    public function returnableGrns(int $supplierId): JsonResponse
    {
        return response()->json(['data' => $this->service->returnableGrns($supplierId)]);
    }

    /** GET /supplier-returns/returnable-items/{grnId} */
    public function returnableItems(int $grnId): JsonResponse
    {
        return response()->json(['data' => $this->service->returnableItems($grnId)]);
    }
}
