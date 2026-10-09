<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Finance\DTOs\AccountCategoryData;
use Modules\Finance\Enums\AccountType;
use Modules\Finance\Http\Requests\AccountCategoryRequest;
use Modules\Finance\Http\Resources\AccountCategoryResource;
use Modules\Finance\Models\AccountCategory;
use Modules\Finance\Services\AccountCategoryService;

class AccountCategoryController extends Controller
{
    public function __construct(private readonly AccountCategoryService $service)
    {
        $this->middleware('permission:view_account_categories')->only(['index', 'show', 'all', 'accountTypes', 'nextCode']);
        $this->middleware('permission:create_account_categories')->only(['store']);
        $this->middleware('permission:edit_account_categories')->only(['update']);
        $this->middleware('permission:delete_account_categories')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $filters   = request()->only(['search', 'account_type', 'is_active']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => AccountCategoryResource::collection($paginator->items()),
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
        $categories = $this->service->all(request()->only(['account_type']));

        return response()->json(['data' => AccountCategoryResource::collection($categories)]);
    }

    /**
     * The five account types, served from the enum.
     *
     * The frontend must not hardcode this list — the digit each type maps to is
     * part of the code scheme, so a stale copy in JavaScript would quietly show
     * the wrong code preview.
     */
    public function accountTypes(): JsonResponse
    {
        return response()->json(['data' => AccountType::options()]);
    }

    /**
     * Non-binding preview of the code this category would receive.
     *
     * Lock-free and display only, exactly like the purchase request
     * next-reference-no endpoint. The authoritative code is allocated again
     * inside the save transaction.
     */
    public function nextCode(): JsonResponse
    {
        $type = AccountType::tryFrom((string) request()->query('account_type'));

        if ($type === null) {
            return response()->json(['message' => 'A valid account_type is required.'], 422);
        }

        return response()->json(['data' => ['code' => $this->service->nextCode($type)]]);
    }

    public function store(AccountCategoryRequest $request): JsonResponse
    {
        $category = $this->service->create(AccountCategoryData::fromRequest($request));

        return response()->json(['data' => (new AccountCategoryResource($category))->toArray($request)], 201);
    }

    public function show(AccountCategory $accountCategory): JsonResponse
    {
        return response()->json(['data' => (new AccountCategoryResource($accountCategory))->toArray(request())]);
    }

    public function update(AccountCategoryRequest $request, AccountCategory $accountCategory): JsonResponse
    {
        $category = $this->service->update($accountCategory, AccountCategoryData::fromRequest($request));

        return response()->json(['data' => (new AccountCategoryResource($category))->toArray($request)]);
    }

    public function destroy(AccountCategory $accountCategory): JsonResponse
    {
        $this->service->delete($accountCategory);

        return response()->json(null, 204);
    }
}
