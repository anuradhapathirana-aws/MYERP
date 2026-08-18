<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleService
{
    /** Roles that cannot be renamed or deleted — core system contracts. */
    private const SYSTEM_ROLES = ['super_admin', 'admin', 'staff'];

    public function all(): Collection
    {
        return Role::with('permissions')->orderBy('name')->get();
    }

    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        return Role::withCount('permissions')->orderBy('name')->paginate($perPage);
    }

    public function find(Role $role): Role
    {
        return $role->load('permissions');
    }

    public function create(string $name): Role
    {
        return Role::create(['name' => $name, 'guard_name' => 'web']);
    }

    public function update(Role $role, string $name): Role
    {
        $role->update(['name' => $name]);

        return $role->fresh(['permissions']);
    }

    public function delete(Role $role): void
    {
        $role->delete();
    }

    public function syncPermissions(Role $role, array $permissionNames): Role
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role->syncPermissions($permissionNames);

        return $role->fresh(['permissions']);
    }

    public function isSystem(Role $role): bool
    {
        return in_array($role->name, self::SYSTEM_ROLES, true);
    }
}
