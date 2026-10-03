<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Models\SupplierGroup;
use App\Models\SupplierMaster;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupplierGroupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view_supplier_groups', 'create_supplier_groups',
            'edit_supplier_groups', 'delete_supplier_groups',
        ];
        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo($permissions);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code'      => 'CONSULTING',
            'name'      => 'Consulting Firms',
            'is_active' => true,
        ], $overrides);
    }

    public function test_the_ten_standard_groups_are_seeded_by_migration(): void
    {
        // The seeding lives in a migration, not a seeder, because the backfill
        // migration depends on TRADE_LOCAL existing.
        $this->assertSame(10, SupplierGroup::count());
        $this->assertDatabaseHas('core_supplier_groups', ['code' => 'TRADE_LOCAL', 'name' => 'Trade (Local)']);
        $this->assertDatabaseHas('core_supplier_groups', ['code' => 'IMPORT_FOREIGN']);
        $this->assertDatabaseHas('core_supplier_groups', ['code' => 'UTILITY']);
    }

    public function test_index_returns_paginated_list(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/supplier-groups')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'code', 'name', 'is_active']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_store_creates_a_group(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-groups', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'CONSULTING')
            ->assertJsonPath('data.name', 'Consulting Firms');

        $this->assertDatabaseHas('core_supplier_groups', ['code' => 'CONSULTING']);
    }

    public function test_store_uppercases_and_trims_the_code(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-groups', $this->payload(['code' => '  leasing  ']))
            ->assertCreated()
            ->assertJsonPath('data.code', 'LEASING');
    }

    public function test_store_rejects_a_duplicate_code(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-groups', $this->payload(['code' => 'TRADE_LOCAL', 'name' => 'Another']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_store_rejects_a_duplicate_name(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-groups', $this->payload(['name' => 'Trade (Local)']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_store_rejects_a_code_with_invalid_characters(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-groups', $this->payload(['code' => 'BAD CODE!']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_store_requires_code_and_name(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-groups', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code', 'name']);
    }

    public function test_update_allows_the_group_to_keep_its_own_code(): void
    {
        $group = SupplierGroup::where('code', 'TRADE_LOCAL')->firstOrFail();

        $this->actingAs($this->user)
            ->putJson("/api/v1/supplier-groups/{$group->id}", [
                'code'      => 'TRADE_LOCAL',
                'name'      => 'Trade (Local) — renamed',
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Trade (Local) — renamed');
    }

    public function test_destroy_deletes_an_unused_group(): void
    {
        $group = SupplierGroup::create(['code' => 'TEMP', 'name' => 'Temporary']);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/supplier-groups/{$group->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('core_supplier_groups', ['id' => $group->id]);
    }

    public function test_destroy_is_blocked_while_suppliers_still_use_the_group(): void
    {
        $group = SupplierGroup::where('code', 'TRADE_LOCAL')->firstOrFail();

        SupplierMaster::create([
            'supplier_code'     => 'SUP-9001',
            'supplier_name'     => 'Assigned Supplier',
            'supplier_group_id' => $group->id,
        ]);

        // Deleting would orphan that supplier's default posting accounts.
        $this->actingAs($this->user)
            ->deleteJson("/api/v1/supplier-groups/{$group->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('core_supplier_groups', ['id' => $group->id, 'deleted_at' => null]);
    }

    public function test_all_endpoint_returns_only_active_groups(): void
    {
        SupplierGroup::create(['code' => 'RETIRED', 'name' => 'Retired Group', 'is_active' => false]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/supplier-groups/all')->assertOk();

        $codes = array_column($response->json('data'), 'code');
        $this->assertNotContains('RETIRED', $codes);
        $this->assertContains('TRADE_LOCAL', $codes);
    }

    public function test_requires_permission(): void
    {
        $other = User::factory()->create(['active_modules' => ['inventory']]);

        $this->actingAs($other)->getJson('/api/v1/supplier-groups')->assertForbidden();
    }
}
