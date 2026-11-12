<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Only the system provider's super-admin account can switch modules on. A client
 * admin who manages users must not be able to take that account over: no seeing
 * it, no resetting its password, no deleting it.
 */
class SuperAdminAccountProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;
    private User $clientAdmin;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['view_users', 'create_users', 'edit_users', 'delete_users'] as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'admin', 'guard_name' => 'web'])
            ->givePermissionTo(['view_users', 'create_users', 'edit_users', 'delete_users']);
        Role::create(['name' => 'staff', 'guard_name' => 'web']);

        $this->provider = User::factory()->create([
            'role' => UserRole::SuperAdmin, 'email' => 'it@provider.test', 'password' => Hash::make('provider123'),
        ]);
        $this->provider->assignRole('super_admin');

        $this->clientAdmin = User::factory()->create(['role' => UserRole::Staff]);
        $this->clientAdmin->assignRole('admin');

        $this->staff = User::factory()->create(['role' => UserRole::Staff]);
        $this->staff->assignRole('staff');
    }

    private function editPayload(User $user, array $extra = []): array
    {
        return array_merge(['name' => $user->name, 'email' => $user->email, 'roles' => ['staff']], $extra);
    }

    public function test_client_admin_cannot_see_the_provider_account(): void
    {
        $emails = collect($this->actingAs($this->clientAdmin)->getJson('/api/users')->assertOk()->json('data'))
            ->pluck('email');

        $this->assertNotContains('it@provider.test', $emails);
        $this->actingAs($this->clientAdmin)->getJson("/api/users/{$this->provider->id}")->assertForbidden();
    }

    public function test_client_admin_cannot_reset_the_provider_password(): void
    {
        $this->actingAs($this->clientAdmin)
            ->putJson("/api/users/{$this->provider->id}", $this->editPayload($this->provider, ['password' => 'hacked123']))
            ->assertForbidden()
            ->assertJsonPath('message', 'This account is managed by your system provider.');

        $this->assertTrue(Hash::check('provider123', $this->provider->fresh()->password));
        $this->assertTrue($this->provider->fresh()->hasRole('super_admin'));
    }

    public function test_client_admin_cannot_delete_the_provider_account(): void
    {
        $this->actingAs($this->clientAdmin)->deleteJson("/api/users/{$this->provider->id}")->assertForbidden();

        $this->assertModelExists($this->provider);
    }

    public function test_a_spatie_only_super_admin_is_protected_too(): void
    {
        $this->staff->syncRoles(['super_admin']);

        $this->actingAs($this->clientAdmin)
            ->putJson("/api/users/{$this->staff->id}", $this->editPayload($this->staff, ['password' => 'hacked123']))
            ->assertForbidden();
    }

    public function test_client_admin_still_manages_ordinary_staff(): void
    {
        $this->actingAs($this->clientAdmin)
            ->putJson("/api/users/{$this->staff->id}", $this->editPayload($this->staff, ['name' => 'Renamed']))
            ->assertOk();

        $this->assertSame('Renamed', $this->staff->fresh()->name);
    }

    public function test_the_provider_manages_its_own_account(): void
    {
        $this->actingAs($this->provider)
            ->putJson("/api/users/{$this->provider->id}", [
                'name' => 'IT Provider', 'email' => 'it@provider.test', 'roles' => ['super_admin'],
            ])
            ->assertOk();

        $this->assertSame('IT Provider', $this->provider->fresh()->name);
    }

    public function test_a_client_user_holding_the_super_admin_role_cannot_hand_it_out(): void
    {
        // Like D.M.S.Bandara on live: the Spatie role, but not the provider flag.
        $roleOnly = User::factory()->create(['role' => UserRole::Staff]);
        $roleOnly->assignRole('super_admin');

        $this->actingAs($roleOnly)->postJson('/api/users', [
            'name' => 'Friend', 'email' => 'friend@example.com', 'password' => 'secret123', 'roles' => ['super_admin'],
        ])->assertCreated();

        $this->assertFalse(User::where('email', 'friend@example.com')->firstOrFail()->hasRole('super_admin'));

        $this->actingAs($roleOnly)
            ->putJson("/api/users/{$this->staff->id}", $this->editPayload($this->staff, ['roles' => ['super_admin']]))
            ->assertOk();
        $this->assertFalse($this->staff->fresh()->hasRole('super_admin'));
    }

    public function test_the_provider_can_give_the_super_admin_role(): void
    {
        $this->actingAs($this->provider)
            ->putJson("/api/users/{$this->staff->id}", $this->editPayload($this->staff, ['roles' => ['super_admin']]))
            ->assertOk();

        $this->assertTrue($this->staff->fresh()->hasRole('super_admin'));
        // The role never brings the System Settings flag with it.
        $this->assertFalse($this->staff->fresh()->isSuperAdmin());
    }

    public function test_client_admin_cannot_create_a_super_admin(): void
    {
        $this->actingAs($this->clientAdmin)->postJson('/api/users', [
            'name' => 'Sneaky', 'email' => 'sneaky@example.com', 'password' => 'secret123', 'roles' => ['super_admin'],
        ])->assertCreated();

        $created = User::where('email', 'sneaky@example.com')->firstOrFail();
        $this->assertFalse($created->hasRole('super_admin'));
        $this->assertFalse($created->isSuperAdmin());
    }
}
