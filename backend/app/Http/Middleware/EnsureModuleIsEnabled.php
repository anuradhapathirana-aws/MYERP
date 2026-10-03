<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleIsEnabled
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Any-of gate: passes when AT LEAST ONE of the given module keys is enabled.
     *
     * Usage in routes:
     *   ->middleware('module:inventory')                 single key
     *   ->middleware('module:inventory,finance,hr')      any-of, for shared core
     *                                                    master data
     */
    public function handle(Request $request, Closure $next, string ...$modules): Response
    {
        foreach ($modules as $module) {
            if ($this->settings->isEnabled("module.{$module}")) {
                return $next($request);
            }
        }

        // Fail closed: a route declaring no keys is a definition bug, not an
        // invitation to let the request through.
        $label = $modules === [] ? '?' : implode('] or [', $modules);

        return response()->json([
            'message' => "The [{$label}] module is not enabled on this installation.",
        ], Response::HTTP_FORBIDDEN);
    }
}
