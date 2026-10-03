<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Models\Bank;
use App\Models\BankBranch;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BankTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view_banks', 'create_banks', 'edit_banks', 'delete_banks',
            'view_bank_branches', 'create_bank_branches', 'edit_bank_branches', 'delete_bank_branches',
        ];
        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo($permissions);
    }

    public function test_index_returns_paginated_list(): void
    {
        // Banks come from BankSeeder, which RefreshDatabase does not run —
        // unlike supplier groups, which a migration seeds.
        Bank::create(['bank_name' => 'Listed Bank', 'bank_code' => 'LST']);

        $this->actingAs($this->user)
            ->getJson('/api/v1/banks')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'bank_name', 'bank_code', 'is_active']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_store_creates_a_bank(): void
    {
        // Codes here must not clash with the reference bank list seeded by
        // migration 2026_09_25_000010, so this uses an obviously fictional one.
        $this->actingAs($this->user)
            ->postJson('/api/v1/banks', [
                'bank_name' => 'Test Community Bank',
                'bank_code' => 'ZZ901',
                'address'   => 'Colombo 02',
            ])
            ->assertCreated()
            ->assertJsonPath('data.bank_code', 'ZZ901');

        $this->assertDatabaseHas('core_banks', ['bank_code' => 'ZZ901']);
    }

    public function test_the_reference_bank_list_is_seeded_by_migration(): void
    {
        // Reference data ships as a migration, not a seeder, so it reaches every
        // environment through `php artisan migrate` alone.
        $this->assertDatabaseHas('core_banks', ['bank_code' => '7010', 'bank_name' => 'Bank of Ceylon']);
        $this->assertDatabaseHas('core_banks', ['bank_code' => '7056', 'bank_name' => 'Commercial Bank of Ceylon PLC']);
        $this->assertGreaterThanOrEqual(17, Bank::count());
    }

    public function test_store_rejects_a_duplicate_bank_code(): void
    {
        Bank::create(['bank_name' => 'Existing', 'bank_code' => '9999']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/banks', ['bank_name' => 'Other', 'bank_code' => '9999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bank_code');
    }

    public function test_store_requires_name_and_code(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/banks', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bank_name', 'bank_code']);
    }

    public function test_update_allows_the_bank_to_keep_its_own_code(): void
    {
        $bank = Bank::create(['bank_name' => 'Renamable', 'bank_code' => '8888']);

        $this->actingAs($this->user)
            ->putJson("/api/v1/banks/{$bank->id}", ['bank_name' => 'Renamed', 'bank_code' => '8888'])
            ->assertOk()
            ->assertJsonPath('data.bank_name', 'Renamed');
    }

    public function test_destroy_deletes_a_bank_without_branches(): void
    {
        $bank = Bank::create(['bank_name' => 'Empty Bank', 'bank_code' => '7777']);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/banks/{$bank->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('core_banks', ['id' => $bank->id]);
    }

    public function test_destroy_is_blocked_while_the_bank_has_branches(): void
    {
        // A raw delete would cascade the branches away silently.
        $bank = Bank::create(['bank_name' => 'Has Branches', 'bank_code' => '6666']);
        BankBranch::create(['bank_id' => $bank->id, 'branch_name' => 'Main', 'branch_code' => '001']);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/banks/{$bank->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('core_banks', ['id' => $bank->id, 'deleted_at' => null]);
    }

    public function test_branch_code_is_unique_within_a_bank_but_reusable_across_banks(): void
    {
        $a = Bank::create(['bank_name' => 'Bank A', 'bank_code' => 'AAA']);
        $b = Bank::create(['bank_name' => 'Bank B', 'bank_code' => 'BBB']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/bank-branches', ['bank_id' => $a->id, 'branch_name' => 'Kandy', 'branch_code' => '010'])
            ->assertCreated();

        // Same code under a DIFFERENT bank is legitimate.
        $this->actingAs($this->user)
            ->postJson('/api/v1/bank-branches', ['bank_id' => $b->id, 'branch_name' => 'Kandy', 'branch_code' => '010'])
            ->assertCreated();

        // Same code under the SAME bank is not.
        $this->actingAs($this->user)
            ->postJson('/api/v1/bank-branches', ['bank_id' => $a->id, 'branch_name' => 'Duplicate', 'branch_code' => '010'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('branch_code');
    }

    public function test_branch_requires_an_existing_bank(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/bank-branches', ['bank_id' => 999999, 'branch_name' => 'Ghost', 'branch_code' => '001'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bank_id');
    }

    public function test_branch_payload_includes_its_bank_without_extra_queries(): void
    {
        $bank = Bank::create(['bank_name' => 'Joined Bank', 'bank_code' => 'JNB']);
        BankBranch::create(['bank_id' => $bank->id, 'branch_name' => 'Galle', 'branch_code' => '020']);

        $this->actingAs($this->user)
            ->getJson('/api/v1/bank-branches?bank_id=' . $bank->id)
            ->assertOk()
            ->assertJsonPath('data.0.bank_name', 'Joined Bank')
            ->assertJsonPath('data.0.branch_code', '020');
    }

    public function test_swift_code_is_uppercased(): void
    {
        $bank = Bank::create(['bank_name' => 'Swift Bank', 'bank_code' => 'SWB']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/bank-branches', [
                'bank_id'     => $bank->id,
                'branch_name' => 'Head Office',
                'branch_code' => '001',
                'swift_code'  => 'swbklkl',
            ])
            ->assertCreated()
            ->assertJsonPath('data.swift_code', 'SWBKLKL');
    }

    public function test_requires_permission(): void
    {
        $other = User::factory()->create(['active_modules' => ['inventory']]);

        $this->actingAs($other)->getJson('/api/v1/banks')->assertForbidden();
    }
}
