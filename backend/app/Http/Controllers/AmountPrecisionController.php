<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AmountPrecisionRequest;
use App\Services\AmountPrecision;
use Illuminate\Http\JsonResponse;

/** How many decimals money amounts carry, system-wide (see AmountPrecision). */
class AmountPrecisionController extends Controller
{
    public function __construct(private readonly AmountPrecision $precision) {}

    /** GET /api/system/amount-precision (any signed-in user) */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->precision->status()]);
    }

    /** PUT /api/system/amount-precision (super_admin only; refused once documents exist) */
    public function update(AmountPrecisionRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->precision->update((int) $request->validated('decimals'))]);
    }
}
