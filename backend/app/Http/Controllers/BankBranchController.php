<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\BankBranchData;
use App\Http\Requests\BankBranchRequest;
use App\Http\Resources\BankBranchResource;
use App\Models\BankBranch;
use App\Services\BankBranchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class BankBranchController extends Controller
{
    public function __construct(private readonly BankBranchService $service)
    {
        $this->middleware('permission:view_bank_branches')->only(['index', 'show', 'all']);
        $this->middleware('permission:create_bank_branches')->only(['store']);
        $this->middleware('permission:edit_bank_branches')->only(['update']);
        $this->middleware('permission:delete_bank_branches')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $filters   = request()->only(['search', 'bank_id', 'is_active']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => BankBranchResource::collection($paginator->items()),
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
        $bankId = request()->input('bank_id');

        return response()->json([
            'data' => BankBranchResource::collection(
                $this->service->all($bankId === null || $bankId === '' ? null : (int) $bankId),
            ),
        ]);
    }

    public function store(BankBranchRequest $request): JsonResponse
    {
        $branch = $this->service->create(BankBranchData::fromRequest($request));

        return response()->json(['data' => (new BankBranchResource($branch))->toArray($request)], 201);
    }

    public function show(BankBranch $bankBranch): JsonResponse
    {
        return response()->json([
            'data' => (new BankBranchResource($bankBranch->load('bank:id,bank_name,bank_code')))->toArray(request()),
        ]);
    }

    public function update(BankBranchRequest $request, BankBranch $bankBranch): JsonResponse
    {
        $branch = $this->service->update($bankBranch, BankBranchData::fromRequest($request));

        return response()->json(['data' => (new BankBranchResource($branch))->toArray($request)]);
    }

    public function destroy(BankBranch $bankBranch): JsonResponse
    {
        $this->service->delete($bankBranch);

        return response()->json(null, 204);
    }
}
