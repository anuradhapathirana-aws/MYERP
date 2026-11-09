<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\TaxCode;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Models\Bill;
use Modules\Finance\Models\ChargeType;
use Modules\Finance\Models\LedgerAccount;
use Modules\Inventory\Models\CostingExpenseType;
use Modules\Inventory\Models\CostingItem;
use Modules\Inventory\Models\CostingItemExpense;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GoodsReceivedNoteItem;
use Modules\Inventory\Models\Product;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Finance\Concerns\BillFixtures;
use Tests\TestCase;

/**
 * Costing "Pull from bills": confirmed supplier-bill lines linked to the
 * costing's GRNs are spread over its products BY VALUE, beside any typed
 * estimates, and only their cost part (never claimable VAT) reaches a price.
 *
 * Shipment: A 100 x 200 = 20,000 and B 50 x 600 = 30,000 -> 50,000.
 * Bill: freight 5,000 (10 % of value) and clearing 2,500 + VAT 450 (5 %).
 */
class CostingBillChargeTest extends TestCase
{
    use BillFixtures;
    use RefreshDatabase;

    private CostingExpenseType $freightRow;
    private CostingExpenseType $clearingRow;
    private GoodsReceivedNote $grn;
    private GoodsReceivedNoteItem $itemA;
    private GoodsReceivedNoteItem $itemB;
    private LedgerAccount $importAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBills();
        app(SettingsService::class)->set('module.inventory', true);

        $perms = ['view_costings', 'create_costings', 'edit_costings', 'confirm_costings', 'delete_costings'];
        foreach ($perms as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo($perms);
        $this->user->update(['active_modules' => ['inventory', 'finance']]);

        $this->freightRow  = CostingExpenseType::create(['name' => 'Freight Charges', 'costing_type' => 'fob', 'is_active' => true, 'sort_order' => 1]);
        $this->clearingRow = CostingExpenseType::create(['name' => 'Clearance & Transportation', 'costing_type' => 'fob', 'is_active' => true, 'sort_order' => 2]);

        $this->importAccount = $this->ledger('Import Costs', 'Direct Material Cost');

        ChargeType::where('code', 'FREIGHT')->update(['fob_expense_type_id' => $this->freightRow->id, 'ledger_account_id' => $this->importAccount->id]);
        ChargeType::where('code', 'CLEARING')->update(['fob_expense_type_id' => $this->clearingRow->id, 'ledger_account_id' => $this->importAccount->id]);
        // Customs Duty deliberately has no FOB row here.
        ChargeType::where('code', 'CUSTOMS_DUTY')->update(['fob_expense_type_id' => null, 'ledger_account_id' => $this->importAccount->id]);
        ChargeType::where('code', 'IMPORT_VAT')->update(['fob_expense_type_id' => $this->clearingRow->id, 'ledger_account_id' => $this->importAccount->id]);

        [$this->grn, $this->itemA, $this->itemB] = $this->shipment('GRN-CB-0001');
    }

    public function test_pulled_bills_are_spread_over_the_products_by_value(): void
    {
        $bill      = $this->forwarderBill();
        $costingId = $this->saveCosting($this->sourceIds($bill));

        // per base unit: A fob 200 -> freight 20, clearing 10; B fob 600 -> 60, 30
        $this->assertSame([
            "{$this->itemA->id}|{$this->freightRow->id}"  => 20.0,
            "{$this->itemA->id}|{$this->clearingRow->id}" => 10.0,
            "{$this->itemB->id}|{$this->freightRow->id}"  => 60.0,
            "{$this->itemB->id}|{$this->clearingRow->id}" => 30.0,
        ], $this->billRows($costingId));

        $this->actingAs($this->user)->getJson("/api/v1/costings/{$costingId}")
            ->assertOk()
            // 5,000 + 2,500: the 450 claimable VAT never reaches the cost
            ->assertJsonPath('data.total_additional_expenses', 7500)
            ->assertJsonPath('data.total_landed_cost', 57500)
            ->assertJsonCount(2, 'data.bill_charges')
            ->assertJsonPath('data.bill_charges.1.amount', 2500)
            ->assertJsonCount(0, 'data.bills_not_included');
    }

    public function test_a_typed_estimate_stays_beside_the_pulled_bill(): void
    {
        $bill      = $this->forwarderBill();
        $costingId = $this->saveCosting($this->sourceIds($bill), [
            ['grn_item_id' => $this->itemA->id, 'expenses' => [['expense_type_id' => $this->freightRow->id, 'amount' => 5]]],
        ]);

        $rows = CostingItemExpense::where('costing_id', $costingId)
            ->where('grn_item_id', $this->itemA->id)->where('expense_type_id', $this->freightRow->id)
            ->orderBy('source')->get();

        $this->assertSame(['bill', 'manual'], $rows->pluck('source')->all());
        $this->assertSame(30.0 + 5.0, (float) CostingItem::where('costing_id', $costingId)->where('grn_item_id', $this->itemA->id)->value('expense_total_base'));
    }

    public function test_the_pull_list_marks_each_bill_line(): void
    {
        $bill = $this->confirmedBill([
            'document_no' => 'AGT-77',
            'lines'       => [
                $this->line('FREIGHT', '5000.00'),
                $this->line('CUSTOMS_DUTY', '9000.00'),
                $this->line('IMPORT_VAT', '12000.00'),
            ],
        ]);

        $charges = collect($this->pullList()->json('data.charges'))->keyBy('charge_name');

        $this->assertSame('available', $charges['Freight']['status']);
        $this->assertSame($this->freightRow->id, $charges['Freight']['expense_type_id']);
        $this->assertSame('unmapped', $charges['Customs Duty']['status']);
        // Claimable import VAT is not cost, so it is never offered.
        $this->assertFalse($charges->has('Import VAT'));

        $this->saveCosting([$bill->lines()->where('line_no', 1)->value('id')]);

        $this->assertSame('elsewhere', collect($this->pullList()->json('data.charges'))->firstWhere('charge_name', 'Freight')['status']);
    }

    public function test_a_bill_line_cannot_be_pulled_into_two_costings(): void
    {
        $ids = $this->sourceIds($this->forwarderBill());
        $this->saveCosting($ids);

        $this->actingAs($this->user)->postJson('/api/v1/costings', $this->payload($ids))
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'BILL-2026-0001 (Freight) is already in CST-2026-0001.']);
    }

    public function test_an_unmapped_charge_or_another_grns_charge_is_refused(): void
    {
        $duty = $this->confirmedBill(['document_no' => 'CUS-1', 'lines' => [$this->line('CUSTOMS_DUTY', '9000.00')]]);

        $this->actingAs($this->user)->postJson('/api/v1/costings', $this->payload($this->sourceIds($duty)))
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Customs Duty has no FOB costing row. Set it in Finance > Charge Types.']);

        [$other] = $this->shipment('GRN-CB-0002');
        $foreign = $this->confirmedBill(['document_no' => 'FWD-9', 'lines' => [$this->line('FREIGHT', '100.00', $other->id)]]);

        $this->actingAs($this->user)->postJson('/api/v1/costings', $this->payload($this->sourceIds($foreign)))
            ->assertStatus(422);
    }

    public function test_a_late_bill_is_flagged_on_the_costing(): void
    {
        $costingId = $this->saveCosting([]);

        $this->forwarderBill();

        $this->actingAs($this->user)->getJson("/api/v1/costings/{$costingId}")
            ->assertOk()
            ->assertJsonCount(2, 'data.bills_not_included')
            ->assertJsonPath('data.bills_not_included.0.bill_no', 'BILL-2026-0001');
    }

    public function test_deleting_a_draft_costing_releases_its_bills(): void
    {
        $bill      = $this->forwarderBill();
        $costingId = $this->saveCosting($this->sourceIds($bill));

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/void", ['reason' => 'Wrong', 'reversal_date' => '2026-10-26'])
            ->assertUnprocessable();

        $this->actingAs($this->user)->deleteJson("/api/v1/costings/{$costingId}")->assertNoContent();

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/void", ['reason' => 'Wrong', 'reversal_date' => '2026-10-26'])
            ->assertOk();
    }

    public function test_with_finance_off_nothing_can_be_pulled(): void
    {
        $ids = $this->sourceIds($this->forwarderBill());

        app(SettingsService::class)->set('module.finance', false);

        $this->pullList()->assertJsonPath('data.available', false)->assertJsonCount(0, 'data.charges');

        $this->actingAs($this->user)->postJson('/api/v1/costings', $this->payload($ids))->assertStatus(422);

        // Typed amounts keep working exactly as before.
        $this->saveCosting([], [['grn_item_id' => $this->itemA->id, 'expenses' => [['expense_type_id' => $this->freightRow->id, 'amount' => 5]]]]);
    }

    public function test_the_preview_includes_pulled_bills(): void
    {
        $ids = $this->sourceIds($this->forwarderBill());

        $this->actingAs($this->user)->postJson('/api/v1/costings/calculate-preview', [
            'grn_ids' => [$this->grn->id], 'costing_type' => 'fob', 'apply_sscl' => false, 'apply_vat' => false,
            'items' => [], 'bill_charge_ids' => $ids,
        ])->assertOk()->assertJsonPath('data.summary.total_additional_expenses', 7500);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array{0: GoodsReceivedNote, 1: GoodsReceivedNoteItem, 2: GoodsReceivedNoteItem} */
    private function shipment(string $grnNo): array
    {
        $grn = GoodsReceivedNote::create([
            'grn_no' => $grnNo, 'grn_date' => '2026-10-10', 'supplier_id' => $this->supplier->id,
            'status' => 'confirmed', 'total_amount' => 50000,
        ]);

        $a = GoodsReceivedNoteItem::create(['grn_id' => $grn->id, 'product_id' => Product::factory()->create()->id, 'quantity_received' => 100, 'unit_price' => 200]);
        $b = GoodsReceivedNoteItem::create(['grn_id' => $grn->id, 'product_id' => Product::factory()->create()->id, 'quantity_received' => 50, 'unit_price' => 600]);

        return [$grn, $a, $b];
    }

    /** Freight 5,000 + clearing 2,500 with VAT 450 claimable, both for the shipment. */
    private function forwarderBill(): Bill
    {
        return $this->confirmedBill([
            'document_no' => 'FWD-1',
            'lines'       => [
                $this->line('FREIGHT', '5000.00'),
                $this->line('CLEARING', '2500.00', null, ['tax_code_id' => TaxCode::where('code', 'VAT-IN')->value('id')]),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function line(string $code, string $amount, ?int $grnId = null, array $extra = []): array
    {
        $type = ChargeType::where('code', $code)->firstOrFail();

        return $extra + [
            'charge_type_id'    => $type->id,
            'ledger_account_id' => $type->ledger_account_id,
            'amount'            => $amount,
            'grn_id'            => $grnId ?? $this->grn->id,
        ];
    }

    /** @return list<int> */
    private function sourceIds(Bill $bill): array
    {
        return $bill->lines()->orderBy('line_no')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<int>                  $billChargeIds
     * @param  list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function payload(array $billChargeIds, array $items = []): array
    {
        return [
            'supplier_id'     => $this->supplier->id,
            'costing_type'    => 'fob',
            'grn_ids'         => [$this->grn->id],
            'apply_sscl'      => false,
            'apply_vat'       => false,
            'items'           => $items,
            'bill_charge_ids' => $billChargeIds,
        ];
    }

    /**
     * @param list<int>                  $billChargeIds
     * @param list<array<string, mixed>> $items
     */
    private function saveCosting(array $billChargeIds, array $items = []): int
    {
        return (int) $this->actingAs($this->user)
            ->postJson('/api/v1/costings', $this->payload($billChargeIds, $items))
            ->assertCreated()
            ->json('data.id');
    }

    private function pullList(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->getJson('/api/v1/costings/bill-charges?' . http_build_query(['grn_ids' => [$this->grn->id], 'costing_type' => 'fob']))
            ->assertOk();
    }

    /** @return array<string, float> "grn_item_id|expense_type_id" => per base unit, bill rows only */
    private function billRows(int $costingId): array
    {
        return CostingItemExpense::where('costing_id', $costingId)
            ->where('source', CostingItemExpense::SOURCE_BILL)
            ->orderBy('grn_item_id')->orderBy('expense_type_id')
            ->get()
            ->mapWithKeys(fn ($e) => ["{$e->grn_item_id}|{$e->expense_type_id}" => round((float) $e->amount, 4)])
            ->all();
    }
}
