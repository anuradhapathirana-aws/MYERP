<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Finance\DTOs\ControlAccountData;
use Modules\Finance\Http\Requests\ControlAccountRequest;
use Modules\Finance\Http\Resources\ControlAccountResource;
use Modules\Finance\Models\AccountCategory;
use Modules\Finance\Models\ControlAccount;
use Modules\Finance\Services\ControlAccountService;

class ControlAccountController extends Controller
{
    public function __construct(private readonly ControlAccountService $service)
    {
        $this->middleware('permission:view_control_accounts')->only(['index', 'show', 'all', 'nextCode']);
        $this->middleware('permission:create_control_accounts')->only(['store']);
        $this->middleware('permission:edit_control_accounts')->only(['update']);
        $this->middleware('permission:delete_control_accounts')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $filters   = request()->only(['search', 'account_category_id', 'account_type', 'is_active']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => ControlAccountResource::collection($paginator->items()),
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
        $accounts = $this->service->all(request()->only(['account_category_id', 'account_type']));

        return response()->json(['data' => ControlAccountResource::collection($accounts)]);
    }

    /** Lock-free preview of the code this control account would receive. */
    public function nextCode(): JsonResponse
    {
        $category = AccountCategory::find(request()->query('account_category_id'));

        if ($category === null) {
            return response()->json(['message' => 'A valid account_category_id is required.'], 422);
        }

        return response()->json(['data' => ['code' => $this->service->nextCode($category)]]);
    }

    public function store(ControlAccountRequest $request): JsonResponse
    {
        $account = $this->service->create(ControlAccountData::fromRequest($request));

        return response()->json(['data' => (new ControlAccountResource($account))->toArray($request)], 201);
    }

    public function show(ControlAccount $controlAccount): JsonResponse
    {
        return response()->json([
            'data' => (new ControlAccountResource($controlAccount->load('category')))->toArray(request()),
        ]);
    }

    public function update(ControlAccountRequest $request, ControlAccount $controlAccount): JsonResponse
    {
        $account = $this->service->update($controlAccount, ControlAccountData::fromRequest($request));

        return response()->json(['data' => (new ControlAccountResource($account))->toArray($request)]);
    }

    public function destroy(ControlAccount $controlAccount): JsonResponse
    {
        $this->service->delete($controlAccount);

        return response()->json(null, 204);
    }
}
