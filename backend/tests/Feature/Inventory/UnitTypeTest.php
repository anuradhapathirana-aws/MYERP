<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Enums\UnitPosition;
use Modules\Inventory\Models\UnitCategory;
use Modules\Inventory\Models\UnitType;
use Tests\TestCase;

class UnitTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private UnitCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);

        $this->user = User::factory()->create([
            'active_modules' => ['inventory'],
        ]);

        $this->category = UnitCategory::factory()->create(['name' => 'Weight']);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'unit_category_id' => $this->category->id,
            'name'             => 'Kilogram',
            'symbol'           => 'kg',
            'country'          => null,
            'unit_position'    => UnitPosition::Suffix->value,
        ], $overrides);
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/unit-types')->assertUnauthorized();
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_paginated_list_with_category(): void
    {
        UnitType::factory()->count(3)->create(['unit_category_id' => $this->category->id]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/unit-types')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'symbol', 'unit_position', 'category' => ['id', 'name']]],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 3);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_a_unit_type(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/unit-types', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Kilogram')
            ->assertJsonPath('data.symbol', 'kg')
            ->assertJsonPath('data.unit_position', 'suffix')
            ->assertJsonPath('data.category.name', 'Weight');

        $this->assertDatabaseHas('inv_unit_types', ['name' => 'Kilogram', 'symbol' => 'kg']);
    }

    public function test_store_requires_category_id(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/unit-types', $this->validPayload(['unit_category_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unit_category_id']);
    }

    public function test_store_rejects_nonexistent_category_id(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/unit-types', $this->validPayload(['unit_category_id' => 9999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unit_category_id']);
    }

    public function test_store_requires_name(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/unit-types', $this->validPayload(['name' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_requires_symbol(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/unit-types', $this->validPayload(['symbol' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['symbol']);
    }

    public function test_store_rejects_invalid_unit_position(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/unit-types', $this->validPayload(['unit_position' => 'center']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unit_position']);
    }

    public function test_store_accepts_prefix_position(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/unit-types', $this->validPayload([
                'name'          => 'Dollar',
                'symbol'        => '$',
                'unit_position' => UnitPosition::Prefix->value,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.unit_position', 'prefix');
    }

    public function test_store_accepts_optional_country(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/unit-types', $this->validPayload(['country' => 'US']))
            ->assertCreated()
            ->assertJsonPath('data.country', 'US');
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_unit_type_with_category(): void
    {
        $unitType = UnitType::factory()->create($this->validPayload());

        $this->actingAs($this->user)
            ->getJson("/api/v1/unit-types/{$unitType->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $unitType->id)
            ->assertJsonPath('data.category.name', 'Weight');
    }

    public function test_show_returns_404_for_missing_type(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/unit-types/9999')
            ->assertNotFound();
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_unit_type(): void
    {
        $unitType = UnitType::factory()->create($this->validPayload());

        $this->actingAs($this->user)
            ->putJson("/api/v1/unit-types/{$unitType->id}", $this->validPayload([
                'name'   => 'Gram',
                'symbol' => 'g',
            ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Gram')
            ->assertJsonPath('data.symbol', 'g');

        $this->assertDatabaseHas('inv_unit_types', ['id' => $unitType->id, 'name' => 'Gram']);
    }

    public function test_update_can_change_category(): void
    {
        $newCategory = UnitCategory::factory()->create(['name' => 'Volume']);
        $unitType    = UnitType::factory()->create($this->validPayload());

        $this->actingAs($this->user)
            ->putJson("/api/v1/unit-types/{$unitType->id}", $this->validPayload([
                'unit_category_id' => $newCategory->id,
                'name'             => 'Litre',
                'symbol'           => 'L',
            ]))
            ->assertOk()
            ->assertJsonPath('data.category.name', 'Volume');
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_unit_type(): void
    {
        $unitType = UnitType::factory()->create($this->validPayload());

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/unit-types/{$unitType->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('inv_unit_types', ['id' => $unitType->id]);
    }
}
