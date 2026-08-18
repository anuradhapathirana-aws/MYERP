<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\SalesChannelData;
use Modules\Inventory\Http\Requests\SalesChannelRequest;
use Modules\Inventory\Http\Resources\SalesChannelResource;
use Modules\Inventory\Models\SalesChannel;
use Modules\Inventory\Services\SalesChannelService;

class SalesChannelController extends Controller
{
    public function __construct(private readonly SalesChannelService $service)
    {
        $this->middleware('permission:view_sales_channels')->only(['index', 'show', 'all']);
        $this->middleware('permission:create_sales_channels')->only(['store']);
        $this->middleware('permission:edit_sales_channels')->only(['update']);
        $this->middleware('permission:delete_sales_channels')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $paginator = $this->service->paginate();

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (SalesChannel $c) => (new SalesChannelResource($c))->toArray(request()))
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

    public function store(SalesChannelRequest $request): JsonResponse
    {
        $channel = $this->service->create(SalesChannelData::fromRequest($request));

        return response()->json(
            ['data' => (new SalesChannelResource($channel))->toArray(request())],
            201,
        );
    }

    public function show(SalesChannel $salesChannel): JsonResponse
    {
        return response()->json(
            ['data' => (new SalesChannelResource($salesChannel))->toArray(request())],
        );
    }

    public function update(SalesChannelRequest $request, SalesChannel $salesChannel): JsonResponse
    {
        $channel = $this->service->update($salesChannel, SalesChannelData::fromRequest($request));

        return response()->json(
            ['data' => (new SalesChannelResource($channel))->toArray(request())],
        );
    }

    public function destroy(SalesChannel $salesChannel): JsonResponse
    {
        $this->service->delete($salesChannel);

        return response()->json(null, 204);
    }

    /** Flat list for dropdowns — id + name + type. */
    public function all(): JsonResponse
    {
        $items = SalesChannel::orderBy('id')
            ->get(['id', 'sales_channel_name', 'type'])
            ->map(fn (SalesChannel $c) => [
                'id'   => $c->id,
                'name' => $c->sales_channel_name,
                'type' => $c->type?->value,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $items]);
    }
}
