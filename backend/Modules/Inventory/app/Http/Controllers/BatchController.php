<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Inventory\Enums\BatchStatus;
use Modules\Inventory\Http\Resources\BatchResource;
use Modules\Inventory\Models\Batch;
use Modules\Inventory\Services\BatchService;

class BatchController extends Controller
{
    public function __construct(private readonly BatchService $service) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) ($request->query('per_page', 25));
        $batches = $this->service->paginate($perPage, $request->query());

        return response()->json([
            'data' => BatchResource::collection($batches->items()),
            'meta' => [
                'current_page' => $batches->currentPage(),
                'last_page'    => $batches->lastPage(),
                'per_page'     => $batches->perPage(),
                'total'        => $batches->total(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json([
            'data' => new BatchResource($this->service->find($id)),
        ]);
    }

    public function nextBatchNo(Request $request): JsonResponse
    {
        $request->validate(['product_id' => ['required', 'integer']]);

        return response()->json([
            'data' => ['batch_no' => $this->service->nextBatchNo((int) $request->query('product_id'))],
        ]);
    }

    public function updateStatus(Request $request, Batch $batch): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'string', 'in:active,quarantine,on_hold,recalled,expired,exhausted'],
        ]);

        $updated = $this->service->updateStatus($batch, BatchStatus::from($request->input('status')));

        return response()->json([
            'data'    => new BatchResource($updated),
            'message' => 'Batch status updated.',
        ]);
    }
}
