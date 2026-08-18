<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Inventory\Models\StockReferenceType;

class StockReferenceTypeController extends Controller
{
    /**
     * GET /stock-reference-types/all
     * Active stock ledger reference types for filter dropdowns and display labels.
     */
    public function all(): JsonResponse
    {
        $types = StockReferenceType::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'code', 'label', 'sort_order']);

        return response()->json(['data' => $types]);
    }
}
