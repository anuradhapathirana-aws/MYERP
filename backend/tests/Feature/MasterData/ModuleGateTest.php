<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The proof that the Step 1 extraction actually worked.
 *
 * Shared master data must be reachable when ANY owning module is enabled. If
 * these tests pass with module.inventory switched OFF, the masters genuinely
 * live in core and a Finance-only client can use them.
 */
class ModuleGateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Inventory explicitly OFF, Finance ON — the sellable-module scenario.
        app(SettingsService::class)->set('module.inventory', false);
        app(SettingsService::class)->set('module.finance', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view_supplier_masters', 'view_supplier_groups', 'view_banks',
            'view_bank_branches', 'view_employees', 'view_payment_modes',
            'view_locations', 'view_companies', 'view_industries',
            'view_products',
        ];
        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['finance']]);
        $this->user->givePermissionTo($permissions);
    }

    /** @return list<array{0:string}> */
    public static function coreMasterEndpoints(): array
    {
        return [
            'suppliers'       => ['/api/v1/supplier-masters'],
            'supplier groups' => ['/api/v1/supplier-groups'],
            'banks'           => ['/api/v1/banks'],
            'bank branches'   => ['/api/v1/bank-branches'],
            'employees'       => ['/api/v1/employees'],
            'payment modes'   => ['/api/v1/payment-modes'],
            'locations'       => ['/api/v1/locations'],
            'companies'       => ['/api/v1/companies'],
            'industries'      => ['/api/v1/industries'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('coreMasterEndpoints')]
    public function test_core_master_is_reachable_with_only_finance_enabled(string $url): void
    {
        $this->actingAs($this->user)->getJson($url)->assertOk();
    }

    public function test_inventory_only_route_is_still_blocked(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/products')
            ->assertForbidden()
            // The single-key message must stay byte-identical after the
            // middleware was made variadic.
            ->assertJsonPath('message', 'The [inventory] module is not enabled on this installation.');
    }

    public function test_core_master_is_blocked_when_no_owning_module_is_enabled(): void
    {
        app(SettingsService::class)->set('module.finance', false);
        app(SettingsService::class)->set('module.hr', false);

        $this->actingAs($this->user)
            ->getJson('/api/v1/supplier-masters')
            ->assertForbidden();
    }

    public function test_core_master_is_reachable_with_only_inventory_enabled(): void
    {
        app(SettingsService::class)->set('module.inventory', true);
        app(SettingsService::class)->set('module.finance', false);

        $this->actingAs($this->user)->getJson('/api/v1/supplier-masters')->assertOk();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/banks')->assertUnauthorized();
    }

    public function test_permission_is_still_enforced_on_core_masters(): void
    {
        $other = User::factory()->create(['active_modules' => ['finance']]);

        $this->actingAs($other)->getJson('/api/v1/banks')->assertForbidden();
    }
}
