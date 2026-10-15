<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data migration: brings the proforma_invoices permissions to deployments that are already
 * live, where re-running RolesAndPermissionsSeeder is not an option — its syncPermissions()
 * would reset any role a client admin has customised. This only ever ADDS: admin gets every
 * action, staff gets view (the seeder's defaults); nothing already granted is touched.
 */
return new class extends Migration
{
    private const ACTIONS = ['view', 'create', 'edit', 'delete'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = array_map(
            fn (string $action) => Permission::firstOrCreate(['name' => "{$action}_proforma_invoices", 'guard_name' => 'web']),
            self::ACTIONS,
        );

        Role::where('name', 'admin')->where('guard_name', 'web')->first()?->givePermissionTo($permissions);
        Role::where('name', 'staff')->where('guard_name', 'web')->first()?->givePermissionTo('view_proforma_invoices');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', array_map(fn (string $a) => "{$a}_proforma_invoices", self::ACTIONS))
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
