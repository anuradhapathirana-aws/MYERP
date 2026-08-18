<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Inventory\DTOs\AttributeData;
use Modules\Inventory\Http\Requests\AttributeRequest;
use Modules\Inventory\Http\Resources\AttributeResource;
use Modules\Inventory\Models\Attribute;
use Modules\Inventory\Services\AttributeService;

class AttributeController extends Controller
{
    public function __construct(private readonly AttributeService $service)
    {
        $this->middleware('permission:view_attributes')->only(['index', 'show', 'all']);
        $this->middleware('permission:create_attributes')->only(['store']);
        $this->middleware('permission:edit_attributes')->only(['update']);
        $this->middleware('permission:delete_attributes')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $filters   = request()->only(['search', 'attribute_type_id', 'category_id']);
        $paginator = $this->service->paginate(50, $filters);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Attribute $item) => (new AttributeResource($item))->toArray(request()))
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

    public function store(AttributeRequest $request): JsonResponse
    {
        $typeId = (int) $request->validated('attribute_type_id');

        // Parse comma-separated names, trim each, drop empties.
        $names = array_values(array_filter(
            array_map('trim', explode(',', (string) $request->validated('attribute_name'))),
            fn (string $s): bool => $s !== '',
        ));

        // Per-item length guard (the form request only checks the whole string).
        foreach ($names as $name) {
            if (mb_strlen($name) > 100) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors'  => ['attribute_name' => ['"' . $name . '" exceeds the maximum of 100 characters.']],
                ], 422);
            }
        }

        if (count($names) <= 1) {
            $attribute = $this->service->create(new AttributeData(
                attribute_type_id: $typeId,
                attribute_name:    $names[0] ?? '',
            ));

            return response()->json(
                ['data' => (new AttributeResource($attribute))->toArray(request())],
                201,
            );
        }

        $created = $this->service->bulkCreate($typeId, $names);

        return response()->json(
            ['data' => $created->map(fn (Attribute $a) => (new AttributeResource($a))->toArray(request()))->values()->all()],
            201,
        );
    }

    public function show(Attribute $attribute): JsonResponse
    {
        $attribute->load('attributeType');

        return response()->json(
            ['data' => (new AttributeResource($attribute))->toArray(request())],
        );
    }

    public function update(AttributeRequest $request, Attribute $attribute): JsonResponse
    {
        $typeId = (int) $request->validated('attribute_type_id');

        $names = array_values(array_filter(
            array_map('trim', explode(',', (string) $request->validated('attribute_name'))),
            fn (string $s): bool => $s !== '',
        ));

        foreach ($names as $name) {
            if (mb_strlen($name) > 100) {
                return response()->json([
                    'message' => 'Validation failed.',
                    'errors'  => ['attribute_name' => ['"' . $name . '" exceeds the maximum of 100 characters.']],
                ], 422);
            }
        }

        $updated = $this->service->update($attribute, new AttributeData(
            attribute_type_id: $typeId,
            attribute_name:    $names[0] ?? '',
        ));

        if (count($names) > 1) {
            $this->service->bulkCreate($typeId, array_slice($names, 1));
        }

        return response()->json(
            ['data' => (new AttributeResource($updated))->toArray(request())],
        );
    }

    public function destroy(Attribute $attribute): JsonResponse
    {
        $this->service->delete($attribute);

        return response()->json(null, 204);
    }

    /** Flat list for <select> dropdowns — no pagination. */
    public function all(): JsonResponse
    {
        $items = $this->service->all()
            ->map(fn (Attribute $a) => [
                'id'                => $a->id,
                'attribute_type_id' => $a->attribute_type_id,
                'attribute_name'    => $a->attribute_name,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $items]);
    }
}
