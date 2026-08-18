<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\UserData;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class UserService
{
    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        $query = User::with('roles')->orderBy('name');

        if (! $this->callerIsSuperAdmin()) {
            $query->whereDoesntHave('roles', fn ($q) => $q->where('name', 'super_admin'));
        }

        return $query->paginate($perPage);
    }

    public function find(User $user): User
    {
        return $user->load('roles');
    }

    public function create(UserData $data): User
    {
        $isSuperAdmin = $this->callerIsSuperAdmin();

        $user = User::create([
            'name'           => $data->name,
            'email'          => $data->email,
            'active_modules' => $isSuperAdmin ? $data->activeModules : [],
            'password'       => $data->password,
        ]);

        $user->syncRoles($this->filterAllowedRoles($data->roles));

        return $user->load('roles');
    }

    public function update(User $user, UserData $data): User
    {
        $isSuperAdmin = $this->callerIsSuperAdmin();

        $attributes = [
            'name'  => $data->name,
            'email' => $data->email,
        ];

        if ($isSuperAdmin) {
            $attributes['active_modules'] = $data->activeModules;
        }

        if ($data->password !== null) {
            $attributes['password'] = $data->password;
        }

        $user->update($attributes);
        $user->syncRoles($this->filterAllowedRoles($data->roles));

        return $user->fresh('roles');
    }

    public function delete(User $user): void
    {
        $user->tokens()->delete();
        $user->delete();
    }

    private function callerIsSuperAdmin(): bool
    {
        /** @var User|null $caller */
        $caller = Auth::user();

        return $caller?->hasRole('super_admin') ?? false;
    }

    /** Strip 'super_admin' from the requested roles unless the caller is super_admin. */
    private function filterAllowedRoles(array $roles): array
    {
        if ($this->callerIsSuperAdmin()) {
            return $roles;
        }

        return array_values(array_filter($roles, fn (string $r) => $r !== 'super_admin'));
    }
}
