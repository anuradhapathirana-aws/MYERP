<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * System Settings > Modules: the one place a module is switched on or off, and
 * the menu (login / me) follows the same switch the API gate checks.
 */
class ModuleSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        app(SettingsService::class)->set('module.inventory', true);
        app(SettingsService::class)->set('module.finance', false);

        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        // The super_admin route gate reads users.role; the menu reads the Spatie role.
        $this->superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->superAdmin->assignRole('super_admin');
    }

    public function test_super_admin_sees_every_module_with_its_state(): void
    {
        $this->actingAs($this->superAdmin)->getJson('/api/system/modules')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'inventory')
            ->assertJsonPath('data.0.enabled', true)
            ->assertJsonPath('data.1.key', 'finance')
            ->assertJsonPath('data.1.enabled', false);
    }

    public function test_switching_finance_on_opens_its_screens_at_once(): void
    {
        $this->actingAs($this->superAdmin)->getJson('/api/v1/account-categories')
            ->assertForbidden()
            ->assertJsonPath('message', 'The [finance] module is not enabled on this installation.');

        $this->actingAs($this->superAdmin)
            ->putJson('/api/system/modules/finance', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        // The cached setting was refreshed: the gate lets Finance through now.
        $this->actingAs($this->superAdmin)->getJson('/api/v1/account-categories')->assertOk();
        $this->assertTrue(app(SettingsService::class)->isEnabled('module.finance'));
    }

    public function test_every_switch_is_audited(): void
    {
        $this->actingAs($this->superAdmin)->putJson('/api/system/modules/finance', ['enabled' => true])->assertOk();
        $this->actingAs($this->superAdmin)->putJson('/api/system/modules/finance', ['enabled' => false])->assertOk();

        $events = AuditLog::where('auditable_type', 'global_setting')->orderBy('id')->get();

        $this->assertSame(['module_enabled', 'module_disabled'], $events->pluck('event')->all());
        $this->assertSame($this->superAdmin->id, $events[0]->user_id);
        $this->assertSame(['module' => 'finance', 'enabled' => true], $events[0]->new_values);
    }

    public function test_switching_to_the_same_state_changes_nothing(): void
    {
        $this->actingAs($this->superAdmin)->putJson('/api/system/modules/inventory', ['enabled' => true])->assertOk();

        $this->assertSame(0, AuditLog::count());
    }

    public function test_switching_off_keeps_the_data(): void
    {
        $this->actingAs($this->superAdmin)->putJson('/api/system/modules/finance', ['enabled' => true])->assertOk();

        $categoryId = DB::table('fin_account_categories')->insertGetId([
            'account_type' => 'asset', 'code' => 'X1', 'category_name' => 'Test',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->superAdmin)->putJson('/api/system/modules/finance', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->assertDatabaseHas('fin_account_categories', ['id' => $categoryId]);
    }

    public function test_an_unknown_module_is_not_found(): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/system/modules/payroll', ['enabled' => true])
            ->assertNotFound();
    }

    public function test_the_switch_must_be_on_or_off(): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/system/modules/finance', ['enabled' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enabled']);
    }

    public function test_only_super_admin_can_switch_modules(): void
    {
        // Even an admin holding every user/role permission cannot.
        foreach (['view_users', 'edit_users', 'manage_roles'] as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }
        $admin = User::factory()->create();
        $admin->givePermissionTo(['view_users', 'edit_users', 'manage_roles']);

        $this->actingAs($admin)->getJson('/api/system/modules')->assertForbidden();
        $this->actingAs($admin)->putJson('/api/system/modules/finance', ['enabled' => true])->assertForbidden();
        $this->assertFalse(app(SettingsService::class)->isEnabled('module.finance'));
    }

    public function test_me_follows_the_switch_not_the_user(): void
    {
        $staff = User::factory()->create(['active_modules' => ['inventory', 'finance']]);

        $this->actingAs($staff)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.active_modules', ['inventory'])
            ->assertJsonPath('data.user_id', $staff->id)
            ->assertJsonPath('data.is_super_admin', false);

        $this->actingAs($this->superAdmin)->getJson('/api/me')->assertJsonPath('data.is_super_admin', true);

        $this->actingAs($this->superAdmin)->putJson('/api/system/modules/finance', ['enabled' => true])->assertOk();

        $this->actingAs($staff)->getJson('/api/me')
            ->assertJsonPath('data.active_modules', ['inventory', 'finance']);
    }

    public function test_the_old_settings_api_is_audited_and_validated_for_modules(): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/settings/module.finance', ['value' => true])
            ->assertOk()
            ->assertJsonPath('data.value', true);

        $this->assertSame(1, AuditLog::where('event', 'module_enabled')->count());

        $this->actingAs($this->superAdmin)
            ->putJson('/api/settings/module.payroll', ['value' => true])
            ->assertNotFound();
    }

    public function test_user_screen_no_longer_sets_modules(): void
    {
        Role::create(['name' => 'staff', 'guard_name' => 'web']);

        $this->actingAs($this->superAdmin)->postJson('/api/users', [
            'name'           => 'New Clerk',
            'email'          => 'clerk@example.com',
            'password'       => 'secret123',
            'roles'          => ['staff'],
            'active_modules' => ['finance'],
        ])->assertCreated()->assertJsonMissingPath('data.active_modules');

        $this->assertNotSame(['finance'], User::where('email', 'clerk@example.com')->first()->active_modules);
    }
}
