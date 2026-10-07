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
        // create/delete included to match what the live database already
        // grants — the code previously declared only view/edit, so those two
        // permissions existed in production but never appeared in the UI matrix.
        'unit_conversions'  => ['view', 'create', 'edit', 'delete'],
        'sales_channels'    => ['view', 'create', 'edit', 'delete'],
        'attribute_types'   => ['view', 'create', 'edit', 'delete'],
        'attributes'        => ['view', 'create', 'edit', 'delete'],
        'customer_masters'  => ['view', 'create', 'edit', 'delete'],
        'store_types'       => ['view', 'create', 'edit', 'delete'],
        'stores'            => ['view', 'create', 'edit', 'delete'],
        'drivers'           => ['view', 'create', 'edit', 'delete'],
        'vehicle_masters'   => ['view', 'create', 'edit', 'delete'],

        // ── Transactions ─────────────────────────────────────────────
        'purchase_requests'     => ['view', 'create', 'edit', 'delete', 'approve'],
        'purchase_orders'       => ['view', 'create', 'edit', 'delete'],
        'grns'                  => ['view', 'create', 'edit', 'delete', 'confirm'],
        'costings'              => ['view', 'create', 'edit', 'delete', 'confirm'],
        'stock_reconciliations' => ['view', 'create', 'edit', 'delete', 'approve'],
        'costing_expense_types' => ['manage'],
        'sales_orders'          => ['view', 'create', 'edit', 'delete'],
        'delivery_orders'       => ['view', 'create', 'edit', 'delete'],
        'invoices'              => ['view', 'create', 'edit', 'delete'],
        'supplier_payments'     => ['view', 'create', 'edit', 'delete', 'confirm'],
        'supplier_credit_notes' => ['view'],
        'customer_receipts'     => ['view', 'create', 'edit', 'delete', 'confirm'],
        'customer_credit_notes' => ['view'],
        'customer_returns'      => ['view', 'create', 'edit', 'delete', 'confirm'],
        'reports'               => ['view'],
    ];

    /**
     * Shared master data owned by core (app/), not by any one module. Reachable
     * whenever ANY owning module is enabled — see routes/master_data.php.
     *
     * IMPORTANT: the five resources moved out of INVENTORY_RESOURCES in Step 1
     * keep their EXACT permission names (view_supplier_masters stays
     * view_supplier_masters), so existing role assignments survive the move.
     * Spatie's pivot is keyed by permission_id and firstOrCreate never recreates
     * an existing row.
     */
    private const MASTER_DATA_RESOURCES = [
        // ── Moved out of Inventory in Step 1 (names unchanged) ───────
        'supplier_masters'  => ['view', 'create', 'edit', 'delete'],
        'industries'        => ['view', 'create', 'edit', 'delete'],
        'companies'         => ['view', 'create', 'edit', 'delete'],
        'locations'         => ['view', 'create', 'edit', 'delete'],
        'payment_modes'     => ['view', 'create', 'edit', 'delete'],

        // ── New in Step 1 ────────────────────────────────────────────
        'supplier_groups'   => ['view', 'create', 'edit', 'delete'],
        'banks'             => ['view', 'create', 'edit', 'delete'],
        'bank_branches'     => ['view', 'create', 'edit', 'delete'],
        'employees'         => ['view', 'create', 'edit', 'delete'],
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

        $allPermissions = [];
        $staffViewPerms = [];

        // ── Shared master data + Inventory permissions ───────────────────
        foreach ([self::MASTER_DATA_RESOURCES, self::INVENTORY_RESOURCES] as $resources) {
            foreach ($resources as $resource => $actions) {
                foreach ($actions as $action) {
                    $perm = "{$action}_{$resource}";
                    Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
                    $allPermissions[] = $perm;

                    if ($action === 'view') {
                        $staffViewPerms[] = $perm;
                    }
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
        //
        // ADDITIVE ON PURPOSE. These use givePermissionTo, never
        // syncPermissions, because this seeder is re-run on every deployment to
        // register newly added permissions — and on a live system the client's
        // own role configuration is the source of truth, not these defaults.
        //
        // syncPermissions would mean "make the list exactly this" and silently
        // revoke anything an administrator had granted through the UI. On the
        // production database that would have stripped create/edit/delete
        // Sales Orders from the staff role, which real users depend on.
        //
        // givePermissionTo is idempotent: already-granted permissions are left
        // alone, so re-running this seeder is safe.

        // super_admin: holds NO permission rows by design — Gate::before() in
        // AppServiceProvider grants it everything unconditionally.
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        // admin: full access to everything this release knows about.
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->givePermissionTo($allPermissions);

        // staff: read access to shared master data and Inventory as a BASELINE.
        // Anything extra an administrator granted is preserved.
        $staff = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        $staff->givePermissionTo($staffViewPerms);
    }
}
