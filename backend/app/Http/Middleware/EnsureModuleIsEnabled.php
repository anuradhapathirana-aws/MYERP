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
     * Usage in routes:  ->middleware('module:inventory')
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (! $this->settings->isEnabled("module.{$module}")) {
            return response()->json([
                'message' => "The [{$module}] module is not enabled on this installation.",
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
