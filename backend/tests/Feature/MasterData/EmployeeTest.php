<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Models\Employee;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = ['view_employees', 'create_employees', 'edit_employees', 'delete_employees'];
        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo($permissions);
    }

    public function test_index_returns_paginated_list(): void
    {
        Employee::create(['employee_code' => 'EMP-9001', 'employee_name' => 'Test Person']);

        $this->actingAs($this->user)
            ->getJson('/api/v1/employees')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'employee_code', 'employee_name', 'is_active']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_store_creates_an_employee(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/employees', [
                'employee_code' => 'EMP-9002',
                'employee_name' => 'Saman Kumara',
                'designation'   => 'Cashier',
                'department'    => 'Finance',
            ])
            ->assertCreated()
            ->assertJsonPath('data.employee_code', 'EMP-9002');

        $this->assertDatabaseHas('core_employees', ['employee_code' => 'EMP-9002']);
    }

    public function test_location_is_optional(): void
    {
        // Petty cash custodians are not always tied to a branch.
        $this->actingAs($this->user)
            ->postJson('/api/v1/employees', [
                'employee_code' => 'EMP-9003',
                'employee_name' => 'No Location',
                'location_id'   => null,
            ])
            ->assertCreated()
            ->assertJsonPath('data.location_id', null);
    }

    public function test_store_rejects_a_nonexistent_location(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/employees', [
                'employee_code' => 'EMP-9004',
                'employee_name' => 'Bad Location',
                'location_id'   => 999999,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_id');
    }

    public function test_store_rejects_a_duplicate_code(): void
    {
        Employee::create(['employee_code' => 'EMP-9005', 'employee_name' => 'First']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/employees', ['employee_code' => 'EMP-9005', 'employee_name' => 'Second'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('employee_code');
    }

    public function test_store_rejects_an_invalid_email(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/employees', [
                'employee_code' => 'EMP-9006',
                'employee_name' => 'Bad Email',
                'email'         => 'not-an-email',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_store_requires_code_and_name(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/employees', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['employee_code', 'employee_name']);
    }

    public function test_update_allows_the_employee_to_keep_its_own_code(): void
    {
        $employee = Employee::create(['employee_code' => 'EMP-9007', 'employee_name' => 'Before']);

        $this->actingAs($this->user)
            ->putJson("/api/v1/employees/{$employee->id}", [
                'employee_code' => 'EMP-9007',
                'employee_name' => 'After',
            ])
            ->assertOk()
            ->assertJsonPath('data.employee_name', 'After');
    }

    public function test_destroy_soft_deletes_the_employee(): void
    {
        $employee = Employee::create(['employee_code' => 'EMP-9008', 'employee_name' => 'Leaver']);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/employees/{$employee->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('core_employees', ['id' => $employee->id]);
    }

    public function test_all_endpoint_returns_only_active_employees(): void
    {
        Employee::create(['employee_code' => 'EMP-9009', 'employee_name' => 'Active One']);
        Employee::create(['employee_code' => 'EMP-9010', 'employee_name' => 'Inactive One', 'is_active' => false]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/employees/all')->assertOk();

        $codes = array_column($response->json('data'), 'employee_code');
        $this->assertContains('EMP-9009', $codes);
        $this->assertNotContains('EMP-9010', $codes);
    }

    public function test_requires_permission(): void
    {
        $other = User::factory()->create(['active_modules' => ['inventory']]);

        $this->actingAs($other)->getJson('/api/v1/employees')->assertForbidden();
    }
}
