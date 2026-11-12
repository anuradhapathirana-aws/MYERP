<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\LoginData;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function __construct(private readonly ModuleService $modules) {}

    /**
     * @return array<string, mixed> token plus the profile()
     * @throws AuthenticationException
     */
    public function login(LoginData $data): array
    {
        $user = User::where('email', $data->email)->first();

        if (! $user || ! Hash::check($data->password, $user->password)) {
            throw new AuthenticationException('The provided credentials are incorrect.');
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return ['token' => $token] + $this->profile($user);
    }

    /**
     * What the app shell needs about the signed-in user. active_modules is the
     * installation's switched-on modules (System Settings > Modules), the same
     * thing the API gate checks, so the menu and the server can never disagree.
     *
     * @return array{user_id: int, user_name: string, user_email: string, active_modules: list<string>, is_super_admin: bool, roles: list<string>, permissions: list<string>}
     */
    public function profile(User $user): array
    {
        return [
            'user_id'        => $user->id,
            'user_name'      => $user->name,
            'user_email'     => $user->email,
            'active_modules' => $this->modules->enabledKeys(),
            // The same check the super_admin route gate makes (System Settings).
            'is_super_admin' => $user->isSuperAdmin(),
            'roles'          => $user->getRoleNames()->values()->all(),
            'permissions'    => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }
}
