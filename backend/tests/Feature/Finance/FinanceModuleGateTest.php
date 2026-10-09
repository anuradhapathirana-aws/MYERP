<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Finance is sold separately from Inventory. This is the proof.
 *
 * The Step 1 counterpart (ModuleGateTest) proved the shared core masters stay
 * reachable with Inventory switched off. This proves the other half: the
 * Finance screens are reachable with ONLY Finance enabled, and vanish when the
 * client has not bought it — while the masters Finance depends on (banks, bank
 * branches, companies) remain available either way, because they live in core.
 */
class FinanceModuleGateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view_account_categories', 'view_control_accounts', 'view_ledger_accounts',
            'view_banks', 'view_bank_branches', 'view_companies', 'view_products',
        ];
        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['finance']]);
        $this->user->givePermissionTo($permissions);
    }

    /**
     * A Finance-only client: no Inventory at all, yet the whole chart of
     * accounts works and the core masters it depends on are still there.
     */
    public function test_finance_works_with_inventory_disabled(): void
    {
        app(SettingsService::class)->set('module.inventory', false);
        app(SettingsService::class)->set('module.finance', true);

        foreach ([
            '/api/v1/account-categories',
            '/api/v1/control-accounts',
            '/api/v1/ledger-accounts',
        ] as $route) {
            $this->actingAs($this->user)->getJson($route)
                ->assertOk("{$route} must work on a Finance-only installation.");
        }

        // The Ledger Account form needs these three, and they are core — never
        // owned by Inventory, so disabling Inventory cannot take them away.
        foreach ([
            '/api/v1/banks/all',
            '/api/v1/bank-branches/all',
            '/api/v1/companies/all',
        ] as $route) {
            $this->actingAs($this->user)->getJson($route)
                ->assertOk("{$route} is core master data and must survive Inventory being disabled.");
        }

        // Inventory's own routes are correctly gone.
        $this->actingAs($this->user)->getJson('/api/v1/products')->assertForbidden();
    }

    /** A client who has not bought Finance cannot reach any of it. */
    public function test_finance_routes_are_forbidden_when_the_module_is_disabled(): void
    {
        app(SettingsService::class)->set('module.inventory', true);
        app(SettingsService::class)->set('module.finance', false);

        foreach ([
            '/api/v1/account-categories',
            '/api/v1/control-accounts',
            '/api/v1/ledger-accounts',
            '/api/v1/account-categories/account-types',
        ] as $route) {
            $this->actingAs($this->user)->getJson($route)
                ->assertForbidden("{$route} must be unreachable without the Finance module.");
        }
    }

    /**
     * Holding the permission is not enough — the module gate runs first.
     * This is what stops a client who was never sold Finance from reaching it
     * by having an administrator tick a permission box.
     */
    public function test_permission_alone_does_not_bypass_the_module_gate(): void
    {
        app(SettingsService::class)->set('module.finance', false);

        $this->assertTrue($this->user->can('view_account_categories'));

        $this->actingAs($this->user)->getJson('/api/v1/account-categories')->assertForbidden();
    }
}
