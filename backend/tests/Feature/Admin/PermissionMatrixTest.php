<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Roles & Permissions screen: every permission that exists must be grantable
 * there. A permission missing from PermissionController::GROUPS can only be
 * handed out by a super admin, which is how Bills, Bank Deposits etc. were
 * once locked out of the client admin's reach.
 */
class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Seeder perms + the ones module migrations create (Finance, Proforma …).
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo('manage_roles');
    }

    public function test_every_existing_permission_is_listed_on_the_roles_screen(): void
    {
        $listed = collect(
            $this->actingAs($this->admin)->getJson('/api/permissions')
                ->assertOk()
                ->json('data')
        )->flatMap(fn (array $group) => collect($group['resources'])->flatMap(
            fn (array $resource) => $resource['permissions']
        ))->all();

        $missing = Permission::pluck('name')->diff($listed)->values()->all();

        $this->assertSame([], $missing, 'Add these to PermissionController::GROUPS: ' . implode(', ', $missing));
    }

    public function test_finance_and_sales_actions_appear_with_their_extra_columns(): void
    {
        $data = $this->actingAs($this->admin)->getJson('/api/permissions')->assertOk()->json('data');

        if (Permission::where('name', 'post_bank_reconciliations')->exists()) {
            $this->assertSame(
                ['view', 'create', 'edit', 'delete', 'post', 'void'],
                $data['finance_payables']['resources']['bank_reconciliations']['actions']
            );
        }

        $this->assertContains('void', $data['inventory_txn']['resources']['supplier_payments']['actions']);
        $this->assertArrayHasKey('proforma_invoices', $data['inventory_txn']['resources']);
        $this->assertArrayHasKey('taxes', $data['master_data']['resources']);
    }

    /** Live data: whoever could manage Finance settings keeps month-end close. */
    public function test_old_finance_settings_holders_keep_the_period_lock_after_the_split(): void
    {
        $migration = require database_path('migrations/2026_11_13_100001_split_finance_settings_permission.php');

        foreach ([false, true] as $seederRanFirst) {
            Permission::whereIn('name', ['manage_finance_settings', 'manage_finance_period_lock'])->delete();
            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            $old = Permission::create(['name' => 'manage_finance_settings', 'guard_name' => 'web']);
            if ($seederRanFirst) {
                Permission::create(['name' => 'manage_finance_period_lock', 'guard_name' => 'web']);
            }

            $role = \Spatie\Permission\Models\Role::findOrCreate('accountant', 'web');
            $role->givePermissionTo($old);
            $direct = User::factory()->create();
            $direct->givePermissionTo($old);

            $migration->up();
            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            $this->assertFalse(Permission::where('name', 'manage_finance_settings')->exists());
            $this->assertTrue($role->fresh()->hasPermissionTo('manage_finance_period_lock'));
            $this->assertTrue($direct->fresh()->hasPermissionTo('manage_finance_period_lock'));
        }
    }

    public function test_user_without_manage_roles_cannot_read_the_matrix(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/permissions')->assertForbidden();
    }
}
