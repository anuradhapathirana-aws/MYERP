<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Inventory\Http\Resources\GrnItemPieceResource;
use Modules\Inventory\Models\GrnItemPiece;

class GrnItemPieceController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view_grns')->only(['show']);
    }

    public function show(string $pieceCode): JsonResponse
    {
        $piece = GrnItemPiece::with(['product', 'batch', 'store', 'location', 'grn'])
            ->where('piece_code', $pieceCode)
            ->firstOrFail();

        return response()->json(['data' => new GrnItemPieceResource($piece)]);
    }
}
