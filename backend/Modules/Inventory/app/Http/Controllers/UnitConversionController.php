<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Inventory\Http\Requests\SaveUnitConversionRatesRequest;
use Modules\Inventory\Services\UnitConversionService;

class UnitConversionController extends Controller
{
    public function __construct(private readonly UnitConversionService $service)
    {
        $this->middleware('permission:view_unit_conversions')->only(['byCategory']);
        $this->middleware('permission:edit_unit_conversions')->only(['saveRates']);
    }

    /** Return all unit types in the category with their current conversion rates. */
    public function byCategory(int $categoryId): JsonResponse
    {
        $data = $this->service->getByCategoryWithRates($categoryId);

        return response()->json(['data' => $data]);
    }

    /** Save (replace) conversion rates for all units in a category against the selected base unit. */
    public function saveRates(SaveUnitConversionRatesRequest $request): JsonResponse
    {
        $this->service->saveRates(
            $request->integer('category_id'),
            $request->integer('base_unit_type_id'),
            $request->input('rates', []),
        );

        return response()->json(['message' => 'Rates saved successfully.']);
    }
}
