<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use App\Contracts\LandedCostTargets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Finance\DTOs\ChargeTypeData;
use Modules\Finance\Enums\ChargeTreatment;
use Modules\Finance\Http\Requests\ChargeTypeRequest;
use Modules\Finance\Http\Resources\ChargeTypeResource;
use Modules\Finance\Models\ChargeType;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\ChargeTypeService;

/** Finance > Payables > Charge Types. */
class ChargeTypeController extends Controller
{
    public function __construct(private readonly ChargeTypeService $service)
    {
        $this->middleware('permission:view_charge_types')->only(['index', 'options']);
        $this->middleware('permission:create_charge_types')->only(['store']);
        $this->middleware('permission:edit_charge_types')->only(['update']);
        $this->middleware('permission:delete_charge_types')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->service->paginate(50, $request->only(['search']));

        return response()->json([
            'data' => ChargeTypeResource::collection($paginator->items())->toArray($request),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /** Dropdown values for the Charge Type form. */
    public function options(): JsonResponse
    {
        $targets = app(LandedCostTargets::class);

        return response()->json([
            'data' => [
                'treatments'       => ChargeTreatment::options(),
                'expense_accounts' => LedgerAccount::query()
                    ->billable()
                    ->orderBy('code')
                    ->get(['id', 'code', 'ledger_account_name'])
                    ->map(fn (LedgerAccount $a): array => ['id' => $a->id, 'code' => $a->code, 'name' => $a->ledger_account_name]),
                'costing_rows'     => $targets->costingRows(),
                'costing_available' => $targets->isAvailable(),
            ],
        ]);
    }

    public function store(ChargeTypeRequest $request): JsonResponse
    {
        $type = $this->service->create(ChargeTypeData::fromRequest($request));

        return response()->json(['data' => (new ChargeTypeResource($type))->toArray($request)], 201);
    }

    public function update(ChargeTypeRequest $request, ChargeType $chargeType): JsonResponse
    {
        $type = $this->service->update($chargeType, ChargeTypeData::fromRequest($request));

        return response()->json(['data' => (new ChargeTypeResource($type))->toArray($request)]);
    }

    public function destroy(ChargeType $chargeType): JsonResponse
    {
        $this->service->delete($chargeType);

        return response()->json(null, 204);
    }
}
