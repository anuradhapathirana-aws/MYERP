<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use App\DTOs\SupplierMasterData;
use App\Http\Requests\SupplierMasterRequest;
use App\Http\Resources\SupplierMasterResource;
use App\Models\SupplierMaster;
use App\Services\SupplierMasterService;

class SupplierMasterController extends Controller
{
    public function __construct(private readonly SupplierMasterService $service)
    {
        $this->middleware('permission:view_supplier_masters')->only(['index', 'show', 'all', 'nextSupplierCode']);
        $this->middleware('permission:create_supplier_masters')->only(['store']);
        $this->middleware('permission:edit_supplier_masters')->only(['update']);
        $this->middleware('permission:delete_supplier_masters')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $filters   = request()->only(['search', 'supplier_group_id', 'mobile', 'bil_city', 'bil_country']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (SupplierMaster $item) => (new SupplierMasterResource($item))->toArray(request()))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(SupplierMasterRequest $request): JsonResponse
    {
        $supplier = $this->service->create(SupplierMasterData::fromRequest($request));

        return response()->json(
            ['data' => (new SupplierMasterResource($supplier))->toArray(request())],
            201,
        );
    }

    public function show(SupplierMaster $supplierMaster): JsonResponse
    {
        return response()->json(
            ['data' => (new SupplierMasterResource($supplierMaster))->toArray(request())],
        );
    }

    public function update(SupplierMasterRequest $request, SupplierMaster $supplierMaster): JsonResponse
    {
        $supplier = $this->service->update($supplierMaster, SupplierMasterData::fromRequest($request));

        return response()->json(
            ['data' => (new SupplierMasterResource($supplier))->toArray(request())],
        );
    }

    public function destroy(SupplierMaster $supplierMaster): JsonResponse
    {
        $this->service->delete($supplierMaster);

        return response()->json(null, 204);
    }

    /** Preview the next auto-generated supplier code (display only, non-locking). */
    public function nextSupplierCode(): JsonResponse
    {
        return response()->json(['data' => ['supplier_code' => $this->service->nextSupplierCode()]]);
    }

    /** Flat list for <select> dropdowns — includes contact & address fields for PO auto-fill. */
    public function all(): JsonResponse
    {
        $items = $this->service->all()
            ->map(fn (SupplierMaster $s) => [
                'id'                    => $s->id,
                'name'                  => $s->supplier_name,
                'supplier_name'         => $s->supplier_name,
                'supplier_group_id'     => $s->supplier_group_id,
                'supplier_group_name'   => $s->group?->name,
                'contact_person_name'   => $s->contact_person_name,
                'contact_person_mobile' => $s->contact_person_mobile,
                'bil_address_line_1'    => $s->bil_address_line_1,
                'bil_address_line_2'    => $s->bil_address_line_2,
                'bil_address_line_3'    => $s->bil_address_line_3,
                'bil_city'              => $s->bil_city,
                'bil_postal_code'       => $s->bil_postal_code,
                'bil_state_province'    => $s->bil_state_province,
                'bil_country'           => $s->bil_country,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $items]);
    }
}
