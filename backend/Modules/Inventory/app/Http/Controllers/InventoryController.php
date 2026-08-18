<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class InventoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json([]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json([]);
    }

    public function destroy(int $id): JsonResponse
    {
        return response()->json([], 204);
    }
}
