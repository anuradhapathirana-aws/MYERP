<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isSuperAdmin()) {
            return response()->json(
                ['message' => 'This action requires super admin privileges.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
