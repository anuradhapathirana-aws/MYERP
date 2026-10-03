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

/**
 * Covers the supplier_type -> supplier_group_id replacement and confirms the
 * supplier endpoints still answer on their original /api/v1 URLs after the
 * move into core.
 */
class SupplierMasterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private SupplierGroup $tradeLocal;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view_supplier_masters', 'create_supplier_masters',
            'edit_supplier_masters', 'delete_supplier_masters',
        ];
        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo($permissions);

        $this->tradeLocal = SupplierGroup::where('code', 'TRADE_LOCAL')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'supplier_name'         => 'Acme Supplies (Pvt) Ltd',
            'supplier_group_id'     => $this->tradeLocal->id,
            'mobile'                => '+94 11 2345678',
            'land_line'             => '+94 11 2345679',
            'email'                 => 'accounts@acme.lk',
            'bil_address_line_1'    => 'No. 1, Main Street',
            'contact_person_name'   => 'Jane Doe',
            'contact_person_mobile' => '+94 77 1234567',
        ], $overrides);
    }

    public function test_url_is_unchanged_after_the_move_to_core(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/supplier-masters')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
    }

    public function test_store_creates_a_supplier_with_a_group(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-masters', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.supplier_group_id', $this->tradeLocal->id);

        $this->assertDatabaseHas('inv_supplier_masters', [
            'supplier_name'     => 'Acme Supplies (Pvt) Ltd',
            'supplier_group_id' => $this->tradeLocal->id,
        ]);
    }

    public function test_supplier_group_is_required(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-masters', $this->payload(['supplier_group_id' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier_group_id');
    }

    public function test_supplier_group_must_exist(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-masters', $this->payload(['supplier_group_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier_group_id');
    }

    /**
     * The bug this replacement fixes: SupplierMasterRequest used to validate
     * supplier_type against Rule::in(['Trade','Service']), yet the seeded data
     * held values like "Grey Fabric" and "Machinery & Spares". Saving any of
     * those suppliers failed validation. Any seeded group now saves cleanly.
     */
    public function test_a_supplier_in_any_seeded_group_can_be_saved(): void
    {
        foreach (SupplierGroup::pluck('id', 'code') as $code => $groupId) {
            $this->actingAs($this->user)
                ->postJson('/api/v1/supplier-masters', $this->payload([
                    'supplier_name'     => "Supplier for {$code}",
                    'supplier_group_id' => $groupId,
                ]))
                ->assertCreated();
        }

        $this->assertSame(SupplierGroup::count(), SupplierMaster::count());
    }

    public function test_payload_exposes_the_group_name_and_no_longer_supplier_type(): void
    {
        SupplierMaster::create([
            'supplier_code'     => 'SUP-9100',
            'supplier_name'     => 'Named Group Supplier',
            'supplier_group_id' => $this->tradeLocal->id,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/supplier-masters')->assertOk();

        $row = collect($response->json('data'))->firstWhere('supplier_code', 'SUP-9100');

        $this->assertSame('Trade (Local)', $row['supplier_group_name']);
        $this->assertArrayNotHasKey('supplier_type', $row);
    }

    public function test_index_can_be_filtered_by_group(): void
    {
        $import = SupplierGroup::where('code', 'IMPORT_FOREIGN')->firstOrFail();

        SupplierMaster::create(['supplier_code' => 'SUP-9101', 'supplier_name' => 'Local One',  'supplier_group_id' => $this->tradeLocal->id]);
        SupplierMaster::create(['supplier_code' => 'SUP-9102', 'supplier_name' => 'Import One', 'supplier_group_id' => $import->id]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/supplier-masters?supplier_group_id=' . $import->id)
            ->assertOk();

        $codes = array_column($response->json('data'), 'supplier_code');
        $this->assertContains('SUP-9102', $codes);
        $this->assertNotContains('SUP-9101', $codes);
    }

    public function test_all_endpoint_exposes_the_group_for_dropdowns(): void
    {
        SupplierMaster::create([
            'supplier_code'     => 'SUP-9103',
            'supplier_name'     => 'Dropdown Supplier',
            'supplier_group_id' => $this->tradeLocal->id,
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/supplier-masters/all')->assertOk();

        $row = collect($response->json('data'))->firstWhere('supplier_name', 'Dropdown Supplier');

        $this->assertSame('Trade (Local)', $row['supplier_group_name']);
        // Address fields the PO form auto-fills from must survive the move.
        $this->assertArrayHasKey('bil_address_line_1', $row);
    }

    public function test_update_changes_the_group(): void
    {
        $supplier = SupplierMaster::create([
            'supplier_code'     => 'SUP-9104',
            'supplier_name'     => 'Regrouped',
            'supplier_group_id' => $this->tradeLocal->id,
        ]);
        $utility = SupplierGroup::where('code', 'UTILITY')->firstOrFail();

        $this->actingAs($this->user)
            ->putJson("/api/v1/supplier-masters/{$supplier->id}", $this->payload([
                'supplier_name'     => 'Regrouped',
                'supplier_group_id' => $utility->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.supplier_group_id', $utility->id);
    }

    public function test_supplier_code_is_auto_generated_and_immutable(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-masters', $this->payload(['supplier_code' => 'HACKED']))
            ->assertCreated();

        $this->assertNotSame('HACKED', $response->json('data.supplier_code'));
        $this->assertStringStartsWith('SUP-', $response->json('data.supplier_code'));
    }

    public function test_requires_permission(): void
    {
        $other = User::factory()->create(['active_modules' => ['inventory']]);

        $this->actingAs($other)->getJson('/api/v1/supplier-masters')->assertForbidden();
    }
}
