<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ModuleService;
use App\Services\SettingsService;
use App\Services\SupportContactService;
use App\Support\Modules\ModuleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSettingController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ModuleService $modules,
    ) {}

    /** GET /api/settings — return all raw rows for the admin panel. */
    public function index(): JsonResponse
    {
        $rows = \App\Models\GlobalSetting::orderBy('key')->get(['key', 'value'])
            ->map(fn ($s) => ['key' => $s->key, 'value' => json_decode($s->value, true)])
            ->values();

        return response()->json(['data' => $rows]);
    }

    /** PUT /api/settings/{key} — update one setting and bust its cache. */
    public function update(Request $request, string $key): JsonResponse
    {
        $request->validate(['value' => ['required']]);

        // Module switches go through ModuleService so they are validated and audited.
        if (str_starts_with($key, 'module.')) {
            $module = substr($key, 7);
            if (! ModuleCatalog::has($module)) {
                return response()->json(['message' => "There is no [{$module}] module."], 404);
            }
            $request->validate(['value' => ['boolean']]);
            $this->modules->setEnabled($module, $request->boolean('value'));

            return response()->json(['data' => ['key' => $key, 'value' => $this->settings->get($key)]]);
        }

        // The support contact has its own validated, audited endpoint.
        if ($key === SupportContactService::SETTING_KEY) {
            return response()->json([
                'message' => 'Update the support contact through System Settings > Support Contact.',
            ], 422);
        }

        $this->settings->set($key, $request->input('value'));

        return response()->json([
            'data' => ['key' => $key, 'value' => $this->settings->get($key)],
        ]);
    }
}
