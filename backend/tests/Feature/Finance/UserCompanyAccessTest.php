<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\CompanyAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Company access on users: no rows = every company, assignments are audited,
 * and a restricted admin cannot grant more than they hold.
 */
class UserCompanyAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private int  $companyA;
    private int  $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['view_users', 'create_users', 'edit_users'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(['view_users', 'create_users', 'edit_users']);

        $this->companyA = $this->company('Alpha');
        $this->companyB = $this->company('Beta');
    }

    private function company(string $name): int
    {
        return (int) DB::table('inv_companies')->insertGetId([
            'company_name' => $name, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'     => 'Clerk',
            'email'    => 'clerk' . uniqid() . '@example.com',
            'roles'    => ['staff'],
            'password' => 'secret123',
        ], $overrides);
    }

    public function test_an_existing_user_without_rows_is_unrestricted(): void
    {
        $this->assertNull(app(CompanyAccessService::class)->allowedCompanyIds($this->admin));
    }

    public function test_companies_can_be_assigned_and_cleared_and_each_change_is_audited(): void
    {
        $id = $this->actingAs($this->admin)
            ->postJson('/api/users', $this->payload(['company_ids' => [$this->companyA]]))
            ->assertCreated()
            ->assertJsonPath('data.company_ids', [$this->companyA])
            ->json('data.id');

        $user = User::findOrFail($id);
        $this->assertSame([$this->companyA], app(CompanyAccessService::class)->allowedCompanyIds($user));

        $this->actingAs($this->admin)->putJson("/api/users/{$id}", $this->payload([
            'email' => $user->email, 'password' => null, 'company_ids' => [],
        ]))->assertOk()->assertJsonPath('data.company_ids', []);

        $this->assertSame(
            2,
            AuditLog::where('auditable_type', 'user')->where('auditable_id', $id)
                ->where('event', 'company_access_changed')->count(),
        );
    }

    public function test_omitting_company_ids_leaves_access_unchanged(): void
    {
        $id = $this->actingAs($this->admin)
            ->postJson('/api/users', $this->payload(['company_ids' => [$this->companyA]]))
            ->json('data.id');

        $user = User::findOrFail($id);

        $this->actingAs($this->admin)->putJson("/api/users/{$id}", [
            'name' => 'Renamed', 'email' => $user->email, 'roles' => ['staff'],
        ])->assertOk()->assertJsonPath('data.company_ids', [$this->companyA]);
    }

    public function test_a_restricted_admin_cannot_grant_companies_they_do_not_hold(): void
    {
        app(CompanyAccessService::class)->sync($this->admin, [$this->companyA]);

        $this->actingAs($this->admin)->postJson('/api/users', $this->payload(['company_ids' => [$this->companyB]]))
            ->assertUnprocessable()->assertJsonValidationErrors('company_ids');

        // "All companies" is more than they hold too.
        $this->actingAs($this->admin)->postJson('/api/users', $this->payload(['company_ids' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('company_ids');

        $this->actingAs($this->admin)->postJson('/api/users', $this->payload(['company_ids' => [$this->companyA]]))
            ->assertCreated();
    }

    public function test_unknown_companies_are_refused(): void
    {
        $this->actingAs($this->admin)->postJson('/api/users', $this->payload(['company_ids' => [99999]]))
            ->assertUnprocessable()->assertJsonValidationErrors('company_ids.0');
    }
}
