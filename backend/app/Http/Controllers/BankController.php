<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\BankData;
use App\Http\Requests\BankRequest;
use App\Http\Resources\BankResource;
use App\Models\Bank;
use App\Services\BankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class BankController extends Controller
{
    public function __construct(private readonly BankService $service)
    {
        $this->middleware('permission:view_banks')->only(['index', 'show', 'all']);
        $this->middleware('permission:create_banks')->only(['store']);
        $this->middleware('permission:edit_banks')->only(['update']);
        $this->middleware('permission:delete_banks')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $filters   = request()->only(['search', 'is_active']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => BankResource::collection($paginator->items()),
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
        return response()->json(['data' => BankResource::collection($this->service->all())]);
    }

    public function store(BankRequest $request): JsonResponse
    {
        $bank = $this->service->create(BankData::fromRequest($request));

        return response()->json(['data' => (new BankResource($bank))->toArray($request)], 201);
    }

    public function show(Bank $bank): JsonResponse
    {
        return response()->json(['data' => (new BankResource($bank))->toArray(request())]);
    }

    public function update(BankRequest $request, Bank $bank): JsonResponse
    {
        $bank = $this->service->update($bank, BankData::fromRequest($request));

        return response()->json(['data' => (new BankResource($bank))->toArray($request)]);
    }

    public function destroy(Bank $bank): JsonResponse
    {
        $this->service->delete($bank);

        return response()->json(null, 204);
    }
}
