<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\CostingData;
use Modules\Inventory\Http\Requests\StoreCostingRequest;
use Modules\Inventory\Http\Requests\UpdateCostingRequest;
use Modules\Inventory\Http\Resources\CostingResource;
use Modules\Inventory\Models\Costing;
use Modules\Inventory\Services\CostingService;

class CostingController extends Controller
{
    public function __construct(private readonly CostingService $service)
    {
        $this->middleware('permission:view_costings')->only(['index', 'show', 'nextDocumentNo', 'nextReferenceNo', 'supplierGrns']);
        $this->middleware('permission:create_costings')->only(['store', 'calculatePreview']);
        $this->middleware('permission:edit_costings')->only(['update']);
        $this->middleware('permission:confirm_costings')->only(['confirm']);
        $this->middleware('permission:delete_costings')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $filters   = $request->only(['search', 'supplier_id', 'costing_type', 'status', 'date_from', 'date_to']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => CostingResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreCostingRequest $request): JsonResponse
    {
        $costing = $this->service->create(CostingData::fromRequest($request));

        return response()->json(
            ['data' => (new CostingResource($costing))->toArray($request)],
            201,
        );
    }

    public function show(Costing $costing): JsonResponse
    {
        $costing = $this->service->find($costing->id);

        return response()->json(
            ['data' => (new CostingResource($costing))->toArray(request())],
        );
    }

    public function update(UpdateCostingRequest $request, Costing $costing): JsonResponse
    {
        $costing = $this->service->update($costing, CostingData::fromRequest($request));

        return response()->json(
            ['data' => (new CostingResource($costing))->toArray($request)],
        );
    }

    public function destroy(Costing $costing): JsonResponse
    {
        $this->service->delete($costing);

        return response()->json(null, 204);
    }

    /** POST /costings/{costing}/confirm */
    public function confirm(Costing $costing): JsonResponse
    {
        $costing = $this->service->confirm($costing);

        return response()->json(
            ['data' => (new CostingResource($costing))->toArray(request())],
        );
    }

    /** GET /costings/next-document-no */
    public function nextDocumentNo(): JsonResponse
    {
        return response()->json(['data' => ['document_no' => $this->service->nextDocumentNo()]]);
    }

    /** GET /costings/next-reference-no */
    public function nextReferenceNo(): JsonResponse
    {
        return response()->json(['data' => ['reference_no' => $this->service->nextReferenceNo()]]);
    }

    /** GET /costings/supplier-grns/{supplierId} */
    public function supplierGrns(int $supplierId): JsonResponse
    {
        return response()->json(['data' => $this->service->getGrnsBySupplier($supplierId)]);
    }

    /** POST /costings/calculate-preview — stateless per-product breakdown, no DB write */
    public function calculatePreview(Request $request): JsonResponse
    {
        $input = $request->only([
            'grn_ids', 'apply_sscl', 'sscl_pct', 'apply_vat', 'vat_pct', 'items',
        ]);

        return response()->json(['data' => $this->service->preview($input)]);
    }
}
