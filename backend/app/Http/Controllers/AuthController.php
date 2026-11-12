<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\LoginData;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(LoginData::fromRequest($request));

        return response()->json($result);
    }

    /** GET /api/me: the app refreshes its menu (modules, roles, permissions) from this on load. */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->authService->profile($user)]);
    }
}
