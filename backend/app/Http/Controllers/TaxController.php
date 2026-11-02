<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\TaxData;
use App\Http\Requests\TaxRequest;
use App\Http\Resources\TaxResource;
use App\Models\Tax;
use App\Services\TaxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class TaxController extends Controller
{
    public function __construct(private readonly TaxService $service)
    {
        $this->middleware('permission:view_taxes')->only(['index', 'show']);
        $this->middleware('permission:create_taxes')->only(['store']);
        $this->middleware('permission:edit_taxes')->only(['update']);
        $this->middleware('permission:delete_taxes')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $paginator = $this->service->paginate(50, request()->only(['search']));

        return response()->json([
            'data' => TaxResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'current_ids'  => $this->service->currentRowIds(),
            ],
        ]);
    }

    /**
     * GET /taxes/current — today's VAT and SSCL for form defaults. Open to every
     * signed-in user of an enabled module: costing and invoice screens need the
     * figures even for staff who may not open the Taxes screen itself.
     */
    public function current(): JsonResponse
    {
        return response()->json(['data' => $this->service->current()]);
    }

    public function store(TaxRequest $request): JsonResponse
    {
        $tax = $this->service->create(TaxData::fromRequest($request));

        return response()->json(['data' => (new TaxResource($tax))->resolve($request)], 201);
    }

    public function show(Tax $tax): JsonResponse
    {
        return response()->json(['data' => (new TaxResource($tax))->resolve(request())]);
    }

    public function update(TaxRequest $request, Tax $tax): JsonResponse
    {
        $tax = $this->service->update($tax, TaxData::fromRequest($request));

        return response()->json(['data' => (new TaxResource($tax))->resolve($request)]);
    }

    public function destroy(Tax $tax): JsonResponse
    {
        $this->service->delete($tax);

        return response()->json(null, 204);
    }
}
