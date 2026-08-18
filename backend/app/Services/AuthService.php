<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\LoginData;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    /**
     * @return array{token: string, active_modules: list<string>}
     * @throws AuthenticationException
     */
    public function login(LoginData $data): array
    {
        $user = User::where('email', $data->email)->first();

        if (! $user || ! Hash::check($data->password, $user->password)) {
            throw new AuthenticationException('The provided credentials are incorrect.');
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return [
            'token'          => $token,
            'user_id'        => $user->id,
            'user_name'      => $user->name,
            'user_email'     => $user->email,
            'active_modules' => $user->active_modules ?? [],
            'roles'          => $user->getRoleNames()->values()->all(),
            'permissions'    => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }
}
