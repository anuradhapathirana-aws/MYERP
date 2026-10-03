<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Models\SupplierGroup;
use App\Models\SupplierMaster;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Models\SupplierPayment;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The supplier group is read LIVE from the supplier rather than copied onto the
 * payment. These tests pin the two behaviours that motivated the change:
 * renaming a group must not strand existing payments, and filtering by group
 * must keep working.
 */
class SupplierPaymentGroupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private SupplierGroup $utility;
    private SupplierGroup $trade;
    private SupplierMaster $utilitySupplier;
    private SupplierMaster $tradeSupplier;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.inventory', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['view_supplier_payments', 'create_supplier_payments'] as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['inventory']]);
        $this->user->givePermissionTo(['view_supplier_payments', 'create_supplier_payments']);

        $this->utility = SupplierGroup::where('code', 'UTILITY')->firstOrFail();
        $this->trade   = SupplierGroup::where('code', 'TRADE_LOCAL')->firstOrFail();

        $this->utilitySupplier = SupplierMaster::create([
            'supplier_code' => 'SUP-8001', 'supplier_name' => 'Ceylon Electricity Board',
            'supplier_group_id' => $this->utility->id,
        ]);
        $this->tradeSupplier = SupplierMaster::create([
            'supplier_code' => 'SUP-8002', 'supplier_name' => 'Local Fabric Co',
            'supplier_group_id' => $this->trade->id,
        ]);
    }

    private function makePayment(SupplierMaster $supplier, string $no): SupplierPayment
    {
        return SupplierPayment::create([
            'payment_no'   => $no,
            'payment_date' => '2026-09-01',
            'supplier_id'  => $supplier->id,
            'status'       => 'draft',
        ]);
    }

    public function test_the_duplicated_column_is_gone(): void
    {
        $this->assertFalse(
            Schema::hasColumn('inv_supplier_payments', 'supplier_type'),
            'supplier_type should have been dropped — the group is read from the supplier.',
        );
    }

    public function test_payment_payload_exposes_the_group_from_the_supplier(): void
    {
        $this->makePayment($this->utilitySupplier, 'PAY-8001');

        $this->actingAs($this->user)
            ->getJson('/api/v1/supplier-payments')
            ->assertOk()
            ->assertJsonPath('data.0.supplier.supplier_group_name', 'Utility Providers')
            ->assertJsonPath('data.0.supplier.supplier_group_id', $this->utility->id);
    }

    /**
     * The failure a stored group NAME would have caused: rename the group and
     * every historical payment silently stops reporting it.
     */
    public function test_renaming_a_group_updates_existing_payments(): void
    {
        $this->makePayment($this->utilitySupplier, 'PAY-8002');

        $this->utility->update(['name' => 'Utilities & Telecom']);

        $this->actingAs($this->user)
            ->getJson('/api/v1/supplier-payments')
            ->assertOk()
            ->assertJsonPath('data.0.supplier.supplier_group_name', 'Utilities & Telecom');
    }

    public function test_payments_can_be_filtered_by_supplier_group(): void
    {
        $this->makePayment($this->utilitySupplier, 'PAY-8003');
        $this->makePayment($this->tradeSupplier, 'PAY-8004');

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/supplier-payments?supplier_group_id=' . $this->utility->id)
            ->assertOk();

        $numbers = array_column($response->json('data'), 'payment_no');
        $this->assertContains('PAY-8003', $numbers);
        $this->assertNotContains('PAY-8004', $numbers);
    }

    /** The filter must follow the supplier when its group is reassigned. */
    public function test_filter_follows_a_supplier_moved_to_another_group(): void
    {
        $this->makePayment($this->tradeSupplier, 'PAY-8005');

        $this->tradeSupplier->update(['supplier_group_id' => $this->utility->id]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/supplier-payments?supplier_group_id=' . $this->utility->id)
            ->assertOk();

        $this->assertContains('PAY-8005', array_column($response->json('data'), 'payment_no'));
    }

    public function test_unfiltered_list_returns_every_payment(): void
    {
        $this->makePayment($this->utilitySupplier, 'PAY-8006');
        $this->makePayment($this->tradeSupplier, 'PAY-8007');

        $this->actingAs($this->user)
            ->getJson('/api/v1/supplier-payments')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
