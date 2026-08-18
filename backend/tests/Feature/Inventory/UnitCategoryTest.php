<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\UnitCategory;
use Tests\TestCase;

class UnitCategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);

        $this->user = User::factory()->create([
            'active_modules' => ['inventory'],
        ]);
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/unit-categories')->assertUnauthorized();
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_paginated_list(): void
    {
        UnitCategory::factory()->count(3)->create();

        $this->actingAs($this->user)
            ->getJson('/api/v1/unit-categories')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'description', 'created_at', 'updated_at']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 3);
    }

    public function test_all_endpoint_returns_flat_list_for_dropdowns(): void
    {
        UnitCategory::factory()->count(5)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/unit-categories/all')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name']]]);

        $this->assertCount(5, $response->json('data'));
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_a_category(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/unit-categories', [
                'name'        => 'Weight',
                'description' => 'Measures of mass',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Weight')
            ->assertJsonPath('data.description', 'Measures of mass');

        $this->assertDatabaseHas('inv_unit_categories', ['name' => 'Weight']);
    }

    public function test_store_accepts_null_description(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/unit-categories', ['name' => 'Volume'])
            ->assertCreated()
            ->assertJsonPath('data.description', null);
    }

    public function test_store_requires_name(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/unit-categories', ['description' => 'No name'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_rejects_name_exceeding_100_chars(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/unit-categories', ['name' => str_repeat('x', 101)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_rejects_description_exceeding_255_chars(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/unit-categories', [
                'name'        => 'Valid',
                'description' => str_repeat('x', 256),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description']);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_single_category(): void
    {
        $category = UnitCategory::factory()->create(['name' => 'Length']);

        $this->actingAs($this->user)
            ->getJson("/api/v1/unit-categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $category->id)
            ->assertJsonPath('data.name', 'Length');
    }

    public function test_show_returns_404_for_missing_category(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/unit-categories/9999')
            ->assertNotFound();
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_name_and_description(): void
    {
        $category = UnitCategory::factory()->create(['name' => 'Old', 'description' => 'Before']);

        $this->actingAs($this->user)
            ->putJson("/api/v1/unit-categories/{$category->id}", [
                'name'        => 'New',
                'description' => 'After',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New')
            ->assertJsonPath('data.description', 'After');

        $this->assertDatabaseHas('inv_unit_categories', ['id' => $category->id, 'name' => 'New']);
    }

    public function test_update_requires_name(): void
    {
        $category = UnitCategory::factory()->create();

        $this->actingAs($this->user)
            ->putJson("/api/v1/unit-categories/{$category->id}", ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_the_category(): void
    {
        $category = UnitCategory::factory()->create();

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/unit-categories/{$category->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('inv_unit_categories', ['id' => $category->id]);
    }

    public function test_destroy_returns_404_for_missing_category(): void
    {
        $this->actingAs($this->user)
            ->deleteJson('/api/v1/unit-categories/9999')
            ->assertNotFound();
    }
}
