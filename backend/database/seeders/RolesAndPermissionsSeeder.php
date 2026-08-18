<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Single source of truth: resource_key => [actions].
     * Permission names are built as "{action}_{resource_key}".
     * Keep this in sync with App\Http\Controllers\PermissionController::GROUPS
     * (that class only controls how these are grouped/labelled in the UI matrix).
     */
    private const INVENTORY_RESOURCES = [
        // ── Master data ──────────────────────────────────────────────
        'products'          => ['view', 'create', 'edit', 'delete'],
        'categories'        => ['view', 'create', 'edit', 'delete'],
        'unit_categories'   => ['view', 'create', 'edit', 'delete'],
        'unit_types'        => ['view', 'create', 'edit', 'delete'],
        'unit_conversions'  => ['view', 'edit'],
        'supplier_masters'  => ['view', 'create', 'edit', 'delete'],
        'sales_channels'    => ['view', 'create', 'edit', 'delete'],
        'industries'        => ['view', 'create', 'edit', 'delete'],
        'companies'         => ['view', 'create', 'edit', 'delete'],
        'locations'         => ['view', 'create', 'edit', 'delete'],
        'attribute_types'   => ['view', 'create', 'edit', 'delete'],
        'attributes'        => ['view', 'create', 'edit', 'delete'],
        'customer_masters'  => ['view', 'create', 'edit', 'delete'],
        'store_types'       => ['view', 'create', 'edit', 'delete'],
        'stores'            => ['view', 'create', 'edit', 'delete'],
        'drivers'           => ['view', 'create', 'edit', 'delete'],
        'vehicle_masters'   => ['view', 'create', 'edit', 'delete'],
        'payment_modes'     => ['view', 'create', 'edit', 'delete'],

        // ── Transactions ─────────────────────────────────────────────
        'purchase_requests'     => ['view', 'create', 'edit', 'delete', 'approve'],
        'purchase_orders'       => ['view', 'create', 'edit', 'delete'],
        'grns'                  => ['view', 'create', 'edit', 'delete', 'confirm'],
        'costings'              => ['view', 'create', 'edit', 'delete', 'confirm'],
        'costing_expense_types' => ['manage'],
        'sales_orders'          => ['view', 'create', 'edit', 'delete'],
        'delivery_orders'       => ['view', 'create', 'edit', 'delete'],
        'invoices'              => ['view', 'create', 'edit', 'delete'],
        'supplier_payments'     => ['view', 'create', 'edit', 'delete', 'confirm'],
        'supplier_credit_notes' => ['view'],
        'customer_receipts'     => ['view', 'create', 'edit', 'delete', 'confirm'],
        'customer_credit_notes' => ['view'],
        'reports'               => ['view'],
    ];

    /**
     * Administration resources (user & role management).
     * Deliberately excluded from the staff read-only default set.
     */
    private const ADMIN_RESOURCES = [
        'users' => ['view', 'create', 'edit', 'delete'],
        'roles' => ['manage'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $allPermissions       = [];
        $inventoryViewPerms   = [];

        // ── Inventory permissions ────────────────────────────────────────
        foreach (self::INVENTORY_RESOURCES as $resource => $actions) {
            foreach ($actions as $action) {
                $perm = "{$action}_{$resource}";
                Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
                $allPermissions[] = $perm;

                if ($action === 'view') {
                    $inventoryViewPerms[] = $perm;
                }
            }
        }

        // ── Administration permissions ───────────────────────────────────
        foreach (self::ADMIN_RESOURCES as $resource => $actions) {
            foreach ($actions as $action) {
                $perm = "{$action}_{$resource}";
                Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
                $allPermissions[] = $perm;
            }
        }

        // ── Roles ────────────────────────────────────────────────────────

        // super_admin: bypasses all gates via Gate::before() in AppServiceProvider
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        // admin: full access to everything by default
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions($allPermissions);

        // staff: read-only access to Inventory by default (no admin permissions)
        $staff = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        $staff->syncPermissions($inventoryViewPerms);
    }
}
