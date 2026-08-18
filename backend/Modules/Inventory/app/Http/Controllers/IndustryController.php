<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\IndustryData;
use Modules\Inventory\Http\Requests\IndustryRequest;
use Modules\Inventory\Http\Resources\IndustryResource;
use Modules\Inventory\Models\Industry;
use Modules\Inventory\Services\IndustryService;

class IndustryController extends Controller
{
    public function __construct(private readonly IndustryService $service)
    {
        $this->middleware('permission:view_industries')->only(['index', 'show', 'all']);
        $this->middleware('permission:create_industries')->only(['store']);
        $this->middleware('permission:edit_industries')->only(['update']);
        $this->middleware('permission:delete_industries')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $paginator = $this->service->paginate();

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Industry $i) => (new IndustryResource($i))->toArray(request()))
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

    public function store(IndustryRequest $request): JsonResponse
    {
        $industry = $this->service->create(IndustryData::fromRequest($request));

        return response()->json(
            ['data' => (new IndustryResource($industry))->toArray(request())],
            201,
        );
    }

    public function show(Industry $industry): JsonResponse
    {
        return response()->json(
            ['data' => (new IndustryResource($industry))->toArray(request())],
        );
    }

    public function update(IndustryRequest $request, Industry $industry): JsonResponse
    {
        $industry = $this->service->update($industry, IndustryData::fromRequest($request));

        return response()->json(
            ['data' => (new IndustryResource($industry))->toArray(request())],
        );
    }

    public function destroy(Industry $industry): JsonResponse
    {
        $this->service->delete($industry);

        return response()->json(null, 204);
    }

    /** Flat list for dropdowns — id + name. */
    public function all(): JsonResponse
    {
        $items = Industry::orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Industry $i) => ['id' => $i->id, 'name' => $i->name])
            ->values()
            ->all();

        return response()->json(['data' => $items]);
    }
}
