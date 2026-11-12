<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\UserData;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class UserService
{
    public function __construct(private readonly CompanyAccessService $companyAccess) {}

    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        $query = User::with(['roles', 'accessCompanies'])->orderBy('name');

        if (! $this->callerIsSuperAdmin()) {
            $query->whereDoesntHave('roles', fn ($q) => $q->where('name', 'super_admin'))
                ->where(fn ($q) => $q->whereNull('role')->orWhere('role', '!=', UserRole::SuperAdmin->value));
        }

        return $query->paginate($perPage);
    }

    /**
     * The system provider's super-admin account (users.role = super_admin) is the
     * only one that can switch modules on after payment. A client admin must not
     * be able to see, edit (e.g. reset its password), or delete it.
     */
    public function assertManageable(User $target): void
    {
        $protected = $target->isSuperAdmin() || $target->hasRole('super_admin');

        if ($protected && ! $this->callerIsSystemAdmin()) {
            throw new AuthorizationException('This account is managed by your system provider.');
        }
    }

    public function find(User $user): User
    {
        return $user->load(['roles', 'accessCompanies']);
    }

    public function create(UserData $data): User
    {
        // Modules are switched per installation (System Settings > Modules), not per user.
        $user = User::create([
            'name'     => $data->name,
            'email'    => $data->email,
            'password' => $data->password,
        ]);

        $user->syncRoles($this->filterAllowedRoles($data->roles));

        if ($data->companyIds !== null) {
            $this->companyAccess->sync($user, $data->companyIds);
        }

        return $user->load(['roles', 'accessCompanies']);
    }

    public function update(User $user, UserData $data): User
    {
        $attributes = [
            'name'  => $data->name,
            'email' => $data->email,
        ];

        if ($data->password !== null) {
            $attributes['password'] = $data->password;
        }

        $user->update($attributes);
        $user->syncRoles($this->filterAllowedRoles($data->roles));

        if ($data->companyIds !== null) {
            $this->companyAccess->sync($user, $data->companyIds);
        }

        return $user->fresh(['roles', 'accessCompanies']);
    }

    public function delete(User $user): void
    {
        $user->tokens()->delete();
        $user->delete();
    }

    /** The same check the System Settings routes make (users.role). */
    private function callerIsSystemAdmin(): bool
    {
        /** @var User|null $caller */
        $caller = Auth::user();

        return $caller?->isSuperAdmin() ?? false;
    }

    private function callerIsSuperAdmin(): bool
    {
        /** @var User|null $caller */
        $caller = Auth::user();

        return $caller?->hasRole('super_admin') ?? false;
    }

    /**
     * Strip 'super_admin' from the requested roles unless the caller is the system
     * provider (users.role = super_admin). Holding the Spatie role alone is not
     * enough, so a client user with that role can never hand it out.
     */
    private function filterAllowedRoles(array $roles): array
    {
        if ($this->callerIsSystemAdmin()) {
            return $roles;
        }

        return array_values(array_filter($roles, fn (string $r) => $r !== 'super_admin'));
    }
}
