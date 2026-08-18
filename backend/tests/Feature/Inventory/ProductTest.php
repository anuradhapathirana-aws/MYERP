<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\Product;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);

        // Reset Spatie cache so freshly created permissions are found
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['view_products', 'create_products', 'edit_products', 'delete_products'] as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo(['view_products', 'create_products', 'edit_products', 'delete_products']);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name'         => 'Test Product',
            'product_code' => 'PRD-00001',
            'product_type' => 'Product',
            'category'     => 'Electronics',
        ], $overrides);
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/products')->assertUnauthorized();
    }

    // ── Permissions ───────────────────────────────────────────────────────────

    public function test_user_without_view_permission_is_forbidden(): void
    {
        $restricted = User::factory()->create(['active_modules' => ['inventory']]);

        $this->actingAs($restricted)
            ->getJson('/api/v1/products')
            ->assertForbidden();
    }

    public function test_user_without_create_permission_is_forbidden(): void
    {
        $restricted = User::factory()->create(['active_modules' => ['inventory']]);
        $restricted->givePermissionTo('view_products');

        $this->actingAs($restricted)
            ->postJson('/api/v1/products', $this->validPayload())
            ->assertForbidden();
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_paginated_list(): void
    {
        Product::factory()->count(3)->create();

        $this->actingAs($this->user)
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'product_code', 'product_type', 'created_at', 'updated_at']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 3);
    }

    public function test_index_includes_suppliers_relationship(): void
    {
        Product::factory()->create(['name' => 'Widget']);

        $this->actingAs($this->user)
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonStructure(['data' => [['suppliers']]]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_a_product(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/products', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Test Product')
            ->assertJsonPath('data.product_code', 'PRD-00001')
            ->assertJsonPath('data.product_type', 'Product')
            ->assertJsonPath('data.lock_purchase', false);

        $this->assertDatabaseHas('inv_products', [
            'name'         => 'Test Product',
            'product_code' => 'PRD-00001',
        ]);
    }

    public function test_store_requires_name(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/products', $this->validPayload(['name' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_rejects_duplicate_product_code(): void
    {
        Product::factory()->create(['product_code' => 'PRD-00001']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/products', $this->validPayload(['product_code' => 'PRD-00001']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['product_code']);
    }

    public function test_store_rejects_invalid_tracking_type(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/products', $this->validPayload(['tracking_type' => 'RFID']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tracking_type']);
    }

    public function test_store_accepts_valid_tracking_types(): void
    {
        foreach (['Batch', 'Serial'] as $type) {
            $this->actingAs($this->user)
                ->postJson('/api/v1/products', $this->validPayload([
                    'product_code'  => "PRD-0000{$type}",
                    'name'          => "Product {$type}",
                    'tracking_type' => $type,
                ]))
                ->assertCreated()
                ->assertJsonPath('data.tracking_type', $type);
        }
    }

    public function test_store_rejects_negative_reorder_values(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/products', $this->validPayload(['reorder_level' => -5]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reorder_level']);
    }

    public function test_store_accepts_all_boolean_flags(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/products', $this->validPayload([
                'lock_purchase'             => true,
                'allow_complimentary_items' => true,
                'free_issue'                => true,
                'allow_minus'               => true,
                'not_allow_direct_sale'     => true,
                'non_returnable'            => true,
                'is_empty'                  => true,
                'service_charge'            => true,
                'loyalty'                   => true,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.lock_purchase', true)
            ->assertJsonPath('data.loyalty', true);
    }

    public function test_store_accepts_null_product_code(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/products', $this->validPayload(['product_code' => null]))
            ->assertCreated()
            ->assertJsonPath('data.product_code', null);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_product_with_suppliers(): void
    {
        $product = Product::factory()->create(['name' => 'Widget']);

        $this->actingAs($this->user)
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'Widget')
            ->assertJsonStructure(['data' => ['suppliers']]);
    }

    public function test_show_returns_404_for_missing_product(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/products/9999')
            ->assertNotFound();
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_update_modifies_product(): void
    {
        $product = Product::factory()->create($this->validPayload());

        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$product->id}", $this->validPayload([
                'name'          => 'Updated Widget',
                'product_type'  => 'Service',
                'reorder_level' => 10.5,
            ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Widget')
            ->assertJsonPath('data.product_type', 'Service');

        $this->assertDatabaseHas('inv_products', [
            'id'   => $product->id,
            'name' => 'Updated Widget',
        ]);
    }

    public function test_update_allows_same_product_code_on_current_record(): void
    {
        $product = Product::factory()->create(['product_code' => 'PRD-00001', 'name' => 'Widget']);

        // Submitting the same product_code for the same product must not fail uniqueness
        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$product->id}", $this->validPayload([
                'product_code' => 'PRD-00001',
                'name'         => 'Widget Updated',
            ]))
            ->assertOk()
            ->assertJsonPath('data.product_code', 'PRD-00001');
    }

    public function test_update_rejects_duplicate_product_code_from_another_record(): void
    {
        Product::factory()->create(['product_code' => 'PRD-00002', 'name' => 'Other']);
        $product = Product::factory()->create(['product_code' => 'PRD-00001', 'name' => 'Widget']);

        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$product->id}", $this->validPayload(['product_code' => 'PRD-00002']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['product_code']);
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_product(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/products/{$product->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('inv_products', ['id' => $product->id]);
    }

    public function test_destroy_returns_404_for_missing_product(): void
    {
        $this->actingAs($this->user)
            ->deleteJson('/api/v1/products/9999')
            ->assertNotFound();
    }
}
