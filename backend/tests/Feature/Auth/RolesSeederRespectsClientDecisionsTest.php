<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * deploy.sh re-runs RolesAndPermissionsSeeder on every release. It may add
 * what a release introduces, but must never undo what a client administrator
 * decided about permissions that already existed.
 */
class RolesSeederRespectsClientDecisionsTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function staff(): Role
    {
        return Role::findByName('staff', 'web')->fresh();
    }

    public function test_a_fresh_install_gets_the_default_sets(): void
    {
        $this->seedRoles();

        $this->assertTrue($this->staff()->hasPermissionTo('view_costings'));
        $this->assertFalse($this->staff()->hasPermissionTo('create_costings'));
        $this->assertTrue(Role::findByName('admin', 'web')->hasPermissionTo('void_bills'));
    }

    public function test_admin_only_screens_are_not_given_to_staff(): void
    {
        $this->seedRoles();
        $admin = Role::findByName('admin', 'web');

        foreach ([
            'view_customer_receipts', 'view_stock_reconciliations', 'view_customer_credit_notes',
            'view_bills', 'view_bill_payments', 'view_journal_entries', 'view_ledger_accounts',
            'view_finance_reports', 'view_users',
        ] as $perm) {
            $this->assertFalse($this->staff()->hasPermissionTo($perm), "staff must not get {$perm}");
            $this->assertTrue($admin->hasPermissionTo($perm), "admin must get {$perm}");
        }

        // Master-data lookups the forms need stay readable.
        foreach (['view_supplier_groups', 'view_banks', 'view_payment_modes'] as $perm) {
            $this->assertTrue($this->staff()->hasPermissionTo($perm), "staff needs {$perm}");
        }
    }

    public function test_a_permission_the_admin_revoked_stays_revoked_after_a_deploy(): void
    {
        $this->seedRoles();
        $this->staff()->revokePermissionTo('view_costings');
        Role::findByName('admin', 'web')->revokePermissionTo('delete_products');

        $this->seedRoles(); // next deployment

        $this->assertFalse($this->staff()->hasPermissionTo('view_costings'));
        $this->assertFalse(Role::findByName('admin', 'web')->fresh()->hasPermissionTo('delete_products'));
    }

    public function test_extra_grants_survive_and_new_permissions_get_their_defaults(): void
    {
        $this->seedRoles();
        $this->staff()->givePermissionTo('create_products');

        // Simulate a release that introduces view_banks for the first time.
        Permission::findByName('view_banks', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->seedRoles();

        $this->assertTrue($this->staff()->hasPermissionTo('create_products'));
        $this->assertTrue($this->staff()->hasPermissionTo('view_banks'));
        $this->assertTrue(Role::findByName('admin', 'web')->fresh()->hasPermissionTo('view_banks'));
    }
}
