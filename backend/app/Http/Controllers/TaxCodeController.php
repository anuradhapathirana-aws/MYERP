<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\TaxCodeData;
use App\Enums\VatCategory;
use App\Http\Requests\TaxCodeRequest;
use App\Http\Resources\TaxCodeResource;
use App\Models\TaxCode;
use App\Services\TaxCodeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/** Same permissions as the Taxes screen: rates and the codes built on them are one area. */
class TaxCodeController extends Controller
{
    public function __construct(private readonly TaxCodeService $service)
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
            'data' => TaxCodeResource::collection($paginator->items()),
            'meta' => [
                'current_page'   => $paginator->currentPage(),
                'last_page'      => $paginator->lastPage(),
                'per_page'       => $paginator->perPage(),
                'total'          => $paginator->total(),
                'vat_categories' => array_map(
                    fn (VatCategory $c) => ['value' => $c->value, 'label' => $c->label(), 'carries_tax' => $c->carriesTax()],
                    VatCategory::cases(),
                ),
            ],
        ]);
    }

    /**
     * GET /tax-codes/options?date=Y-m-d — active purchase codes with their rates
     * on that date. Open to every signed-in user: GRN entry needs it even for
     * staff who never open the Tax Codes screen.
     */
    public function options(): JsonResponse
    {
        $date = request()->date('date');

        return response()->json([
            'data' => $this->service->purchaseOptions($date === null ? null : CarbonImmutable::instance($date)),
        ]);
    }

    public function store(TaxCodeRequest $request): JsonResponse
    {
        $code = $this->service->create(TaxCodeData::fromRequest($request));

        return response()->json(['data' => (new TaxCodeResource($code))->resolve($request)], 201);
    }

    public function show(TaxCode $taxCode): JsonResponse
    {
        return response()->json(['data' => (new TaxCodeResource($taxCode->load('components')))->resolve(request())]);
    }

    public function update(TaxCodeRequest $request, TaxCode $taxCode): JsonResponse
    {
        $code = $this->service->update($taxCode, TaxCodeData::fromRequest($request));

        return response()->json(['data' => (new TaxCodeResource($code))->resolve($request)]);
    }

    public function destroy(TaxCode $taxCode): JsonResponse
    {
        $this->service->delete($taxCode);

        return response()->json(null, 204);
    }
}
