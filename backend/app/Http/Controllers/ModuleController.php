<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ModuleToggleRequest;
use App\Services\ModuleService;
use App\Support\Modules\ModuleCatalog;
use Illuminate\Http\JsonResponse;

/** System Settings > Modules (super_admin only). */
class ModuleController extends Controller
{
    public function __construct(private readonly ModuleService $modules) {}

    /** GET /api/system/modules */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->modules->all()]);
    }

    /** PUT /api/system/modules/{key} { enabled: bool } */
    public function update(ModuleToggleRequest $request, string $key): JsonResponse
    {
        if (! ModuleCatalog::has($key)) {
            return response()->json(['message' => "There is no [{$key}] module."], 404);
        }

        return response()->json([
            'data' => $this->modules->setEnabled($key, $request->boolean('enabled')),
        ]);
    }
}
