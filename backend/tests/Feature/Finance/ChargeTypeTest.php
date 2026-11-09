<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Models\BillLine;
use Modules\Finance\Models\ChargeType;
use Modules\Inventory\Models\CostingExpenseType;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Finance\Concerns\BillFixtures;
use Tests\TestCase;

/** Finance > Payables > Charge Types. */
class ChargeTypeTest extends TestCase
{
    use BillFixtures;
    use RefreshDatabase;

    private const PERMS = ['view_charge_types', 'create_charge_types', 'edit_charge_types', 'delete_charge_types'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBills();
        app(SettingsService::class)->set('module.inventory', true);

        foreach (self::PERMS as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo(self::PERMS);
    }

    public function test_the_built_in_types_are_seeded_with_their_treatments(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/charge-types')->assertOk()->assertJsonCount(9, 'data');

        $this->assertSame([
            'FREIGHT' => 'landed_cost', 'INSURANCE' => 'landed_cost', 'CUSTOMS_DUTY' => 'landed_cost',
            'CUSTOMS_SSCL' => 'landed_cost', 'IMPORT_VAT' => 'recoverable_tax', 'CLEARING' => 'landed_cost',
            'BANK_CHARGES' => 'expense', 'BANK_COMMISSION' => 'expense', 'DEMURRAGE' => 'expense',
        ], ChargeType::orderBy('sort_order')->get()->mapWithKeys(fn ($t) => [$t->code => $t->treatment->value])->all());
    }

    public function test_a_custom_type_is_created_with_its_account_and_costing_rows(): void
    {
        [$fob, $cif] = $this->costingRows();

        $this->actingAs($this->user)->postJson('/api/v1/charge-types', [
            'code' => 'port_handling', 'name' => 'Port Handling', 'treatment' => 'landed_cost',
            'ledger_account_id' => $this->expense->id, 'fob_expense_type_id' => $fob, 'cif_expense_type_id' => $cif,
        ])->assertCreated()
            ->assertJsonPath('data.code', 'PORT_HANDLING')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.fob_expense_type_id', $fob);

        $this->assertDatabaseHas('core_audit_logs', ['auditable_type' => 'charge_type', 'event' => 'created']);
    }

    public function test_an_expense_type_keeps_no_costing_rows(): void
    {
        [$fob] = $this->costingRows();

        $id = $this->actingAs($this->user)->postJson('/api/v1/charge-types', [
            'code' => 'FINE', 'name' => 'Port Fine', 'treatment' => 'expense', 'fob_expense_type_id' => $fob,
        ])->assertCreated()->json('data.id');

        $this->assertNull(ChargeType::find($id)->fob_expense_type_id);
    }

    public function test_the_account_must_be_a_billable_expense_account_and_rows_the_right_list(): void
    {
        [$fob, $cif] = $this->costingRows();
        $bank = (int) DB::table('fin_ledger_accounts')->whereNotNull('cash_book_type')->value('id');

        $this->actingAs($this->user)->postJson('/api/v1/charge-types', [
            'code' => 'X1', 'name' => 'X', 'treatment' => 'landed_cost',
            'ledger_account_id' => $bank ?: 999999,
            // FOB field given a CIF row, CIF field a FOB row
            'fob_expense_type_id' => $cif, 'cif_expense_type_id' => $fob,
        ])->assertUnprocessable()->assertJsonValidationErrors(['ledger_account_id']);

        $this->actingAs($this->user)->postJson('/api/v1/charge-types', [
            'code' => 'X1', 'name' => 'X', 'treatment' => 'landed_cost',
            'fob_expense_type_id' => $cif, 'cif_expense_type_id' => $fob,
        ])->assertUnprocessable()->assertJsonValidationErrors(['fob_expense_type_id', 'cif_expense_type_id']);
    }

    public function test_a_built_in_code_and_the_import_vat_treatment_are_locked(): void
    {
        $freight = ChargeType::where('code', 'FREIGHT')->firstOrFail();
        $vat     = ChargeType::where('code', 'IMPORT_VAT')->firstOrFail();

        $this->actingAs($this->user)->putJson("/api/v1/charge-types/{$freight->id}", $this->payload($freight, ['code' => 'FRT']))
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->actingAs($this->user)->putJson("/api/v1/charge-types/{$vat->id}", $this->payload($vat, ['treatment' => 'landed_cost']))
            ->assertUnprocessable()->assertJsonValidationErrors('treatment');
    }

    public function test_the_accountant_may_move_bank_charges_into_landed_cost(): void
    {
        $bank = ChargeType::where('code', 'BANK_CHARGES')->firstOrFail();

        $this->actingAs($this->user)->putJson("/api/v1/charge-types/{$bank->id}", $this->payload($bank, [
            'treatment' => 'landed_cost', 'ledger_account_id' => $this->expense->id,
        ]))->assertOk()->assertJsonPath('data.treatment', 'landed_cost')->assertJsonPath('data.ledger_account_id', $this->expense->id);
    }

    public function test_built_in_and_used_types_cannot_be_deleted(): void
    {
        $freight = ChargeType::where('code', 'FREIGHT')->firstOrFail();
        $this->actingAs($this->user)->deleteJson("/api/v1/charge-types/{$freight->id}")->assertUnprocessable();

        $id = $this->actingAs($this->user)->postJson('/api/v1/charge-types', [
            'code' => 'COURIER', 'name' => 'Courier', 'treatment' => 'expense',
        ])->assertCreated()->json('data.id');

        $this->createBill(['lines' => [[
            'charge_type_id' => $id, 'ledger_account_id' => $this->expense->id, 'amount' => '500.00',
        ]]]);
        $this->assertSame(1, BillLine::where('charge_type_id', $id)->count());

        $this->actingAs($this->user)->deleteJson("/api/v1/charge-types/{$id}")
            ->assertUnprocessable()->assertJsonValidationErrors('id');
    }

    public function test_an_unused_custom_type_can_be_deleted(): void
    {
        $id = $this->actingAs($this->user)->postJson('/api/v1/charge-types', [
            'code' => 'TEMP', 'name' => 'Temp', 'treatment' => 'expense',
        ])->json('data.id');

        $this->actingAs($this->user)->deleteJson("/api/v1/charge-types/{$id}")->assertNoContent();
        $this->assertNull(ChargeType::find($id));
    }

    public function test_options_list_costing_rows_from_inventory(): void
    {
        $this->costingRows();

        $this->actingAs($this->user)->getJson('/api/v1/charge-types/options')
            ->assertOk()
            ->assertJsonPath('data.costing_available', true)
            ->assertJsonCount(2, 'data.costing_rows')
            ->assertJsonCount(3, 'data.treatments');
    }

    public function test_without_permission_the_screen_is_closed(): void
    {
        $staff = $this->makeUser(['view_bills']);

        $this->actingAs($staff)->getJson('/api/v1/charge-types')->assertForbidden();
        $this->actingAs($staff)->postJson('/api/v1/charge-types', ['code' => 'X', 'name' => 'X', 'treatment' => 'expense'])->assertForbidden();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array{0:int, 1:int} a FOB and a CIF Costing row */
    private function costingRows(): array
    {
        $fob = CostingExpenseType::create(['name' => 'Handling', 'costing_type' => 'fob', 'is_active' => true, 'sort_order' => 1]);
        $cif = CostingExpenseType::create(['name' => 'Handling', 'costing_type' => 'cif', 'is_active' => true, 'sort_order' => 1]);

        return [(int) $fob->id, (int) $cif->id];
    }

    /**
     * @param  array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(ChargeType $type, array $overrides = []): array
    {
        return $overrides + [
            'code'                => $type->code,
            'name'                => $type->name,
            'treatment'           => $type->treatment->value,
            'ledger_account_id'   => $type->ledger_account_id,
            'fob_expense_type_id' => null,
            'cif_expense_type_id' => null,
            'is_active'           => true,
            'sort_order'          => $type->sort_order,
        ];
    }
}
