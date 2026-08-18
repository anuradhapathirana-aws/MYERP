<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSettingController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

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

        $this->settings->set($key, $request->input('value'));

        return response()->json([
            'data' => ['key' => $key, 'value' => $this->settings->get($key)],
        ]);
    }
}
