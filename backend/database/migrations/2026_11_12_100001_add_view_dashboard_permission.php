<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data migration: brings view_dashboard to deployments that are already live,
 * where re-running RolesAndPermissionsSeeder must not reset customised roles.
 * Only ever ADDS. Admin and staff both get it — the dashboard itself reveals
 * nothing extra: every section also requires that screen's own view right.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(['name' => 'view_dashboard', 'guard_name' => 'web']);

        foreach (['admin', 'staff'] as $role) {
            Role::where('name', $role)->where('guard_name', 'web')->first()?->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'view_dashboard')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
