<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\TaxCode;
use App\Services\SettingsService;
use App\Support\Journal\PostingRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Enums\BillStatus;
use Modules\Finance\Models\AccountDefault;
use Modules\Finance\Models\Bill;
use Modules\Finance\Models\ChargeType;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Models\LedgerAccount;
use Tests\Feature\Finance\Concerns\BillFixtures;
use Tests\TestCase;

/**
 * Multi-line supplier bills with charge types, tax and a GRN link -- the
 * clearing agent's bill, the CusDec, the freight forwarder.
 *
 * The journal must always be complete: each line's cost to its account,
 * every claimable tax to Input Tax Recoverable, the bill total to the payable.
 */
class LandedCostBillTest extends TestCase
{
    use BillFixtures;
    use RefreshDatabase;

    private LedgerAccount $clearingAccount;
    private LedgerAccount $dutyAccount;
    private int $grnId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBills();
        app(SettingsService::class)->set('module.inventory', true);

        $this->clearingAccount = $this->ledger('Clearing & Transport In', 'Direct Material Cost');
        $this->dutyAccount     = $this->ledger('Import Duty & Levies', 'Direct Material Cost');

        ChargeType::where('code', 'CLEARING')->update(['ledger_account_id' => $this->clearingAccount->id]);
        ChargeType::whereIn('code', ['CUSTOMS_DUTY', 'CUSTOMS_SSCL', 'IMPORT_VAT'])->update(['ledger_account_id' => $this->dutyAccount->id]);

        $this->grnId = $this->makeGrn('GRN-2026-0101', 'confirmed');
    }

    // ── Posting ─────────────────────────────────────────────────────────────

    public function test_an_agent_bill_posts_each_charge_and_claims_all_vat(): void
    {
        $bill = $this->confirmedBill($this->agentBill());

        $this->assertSame('1204200.00', (string) $bill->total_amount);
        $this->assertSame('7200.00', (string) $bill->tax_amount);

        $this->assertSame([
            $this->clearingAccount->id                         => 40000.0,
            $this->dutyAccount->id                             => 477000.0,
            AccountRole::InputTaxRecoverable->value            => 687200.0,
            AccountRole::AccountsPayable->value                => -1204200.0,
        ], $this->journal($bill));
    }

    public function test_the_same_account_may_carry_two_lines(): void
    {
        $bill = $this->confirmedBill(['lines' => [
            $this->line('CUSTOMS_DUTY', '477000.00'),
            $this->line('CUSTOMS_SSCL', '85000.00'),
        ]]);

        $this->assertSame([
            $this->dutyAccount->id              => 562000.0,
            AccountRole::AccountsPayable->value => -562000.0,
        ], $this->journal($bill));
    }

    public function test_a_vat_inclusive_amount_is_split_back_to_before_tax(): void
    {
        $bill = $this->createBill([
            'prices_include_tax' => true,
            'lines'              => [$this->line('CLEARING', '47200.00', ['tax_code_id' => $this->taxCode('VAT-IN')])],
        ]);

        $line = $bill->lines()->sole();
        $this->assertSame('40000.00', (string) $line->amount);
        $this->assertSame('47200.00', (string) $line->amount_incl);
        $this->assertSame('7200.00', (string) $line->tax_amount);
        $this->assertSame('47200.00', (string) $line->line_total);
        $this->assertSame('7200.00', (string) $line->recoverable_tax);
        $this->assertSame('47200.00', (string) $bill->total_amount);
    }

    public function test_each_tax_is_kept_per_line_for_the_vat_return(): void
    {
        $bill = $this->createBill($this->agentBill());

        $rows = DB::table('fin_bill_line_taxes')->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $this->assertEquals(['VAT', 18.0, 40000.0, 7200.0, 1], [
            $rows[0]->tax_code_ref, (float) $rows[0]->rate, (float) $rows[0]->base, (float) $rows[0]->amount, (int) $rows[0]->is_recoverable,
        ]);
        // The CusDec gives the import VAT amount only.
        $this->assertEquals(['VAT', null, null, 680000.0, 1], [
            $rows[1]->tax_code_ref, $rows[1]->rate, $rows[1]->base, (float) $rows[1]->amount, (int) $rows[1]->is_recoverable,
        ]);
    }

    public function test_with_vat_off_import_vat_and_agent_vat_are_cost(): void
    {
        DB::table('core_taxes')->where('code', 'VAT')->update(['is_active' => false]);
        Cache::forget('core_taxes.rows');

        $bill = $this->confirmedBill($this->agentBill());

        $this->assertSame([
            $this->clearingAccount->id          => 47200.0,
            $this->dutyAccount->id              => 1157000.0,
            AccountRole::AccountsPayable->value => -1204200.0,
        ], $this->journal($bill));
    }

    public function test_voiding_reverses_every_line(): void
    {
        $bill = $this->confirmedBill($this->agentBill());

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/void", [
            'reason' => 'Agent re-issued the invoice', 'reversal_date' => '2026-10-26',
        ])->assertOk();

        $original = JournalEntry::with('lines')->forSource(PostingRole::SOURCE_BILL, $bill->id)->sole();
        $reversal = JournalEntry::with('lines')->findOrFail($original->reversed_by_journal_id);

        $this->assertCount($original->lines->count(), $reversal->lines);

        $net = $original->lines->concat($reversal->lines)
            ->groupBy('ledger_account_id')
            ->map(fn ($lines) => round((float) $lines->sum(fn ($l) => (float) $l->debit - (float) $l->credit), 2))
            ->filter(fn ($v) => $v != 0.0);

        $this->assertSame(BillStatus::Voided, $bill->fresh()->status);
        $this->assertCount(0, $net, 'every account nets to zero after the void');
    }

    public function test_a_bill_pulled_into_a_costing_cannot_be_voided(): void
    {
        $bill = $this->confirmedBill($this->agentBill());

        $costingId = DB::table('inv_costings')->insertGetId([
            'document_no' => 'CST-2026-0007', 'reference_no' => 'CRef-0007', 'supplier_id' => $this->supplier->id,
            'costing_type' => 'fob', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inv_costing_bill_charges')->insert([
            'costing_id' => $costingId, 'source_type' => 'bill_line', 'source_id' => $bill->lines()->first()->id,
            'bill_no' => $bill->bill_no, 'charge_name' => 'Clearing', 'grn_id' => $this->grnId,
            'expense_type_id' => 1, 'amount' => 40000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/void", [
            'reason' => 'Wrong', 'reversal_date' => '2026-10-26',
        ])->assertUnprocessable()->assertJsonFragment(['status' => ["{$bill->bill_no} is included in Costing CST-2026-0007. Remove it from that costing before voiding."]]);

        // A deleted draft costing lets go of it.
        DB::table('inv_costings')->where('id', $costingId)->update(['deleted_at' => now()]);

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/void", [
            'reason' => 'Wrong', 'reversal_date' => '2026-10-26',
        ])->assertOk();
    }

    public function test_an_old_style_single_expense_line_posts_as_before(): void
    {
        $bill = $this->confirmedBill();

        $this->assertSame([
            $this->expense->id                  => 15000.5,
            AccountRole::AccountsPayable->value => -15000.5,
        ], $this->journal($bill));
        $this->assertNull($bill->lines()->sole()->treatment);
    }

    // ── GRN link and validation ─────────────────────────────────────────────

    public function test_a_landed_cost_line_links_to_a_confirmed_grn_and_shows_its_number(): void
    {
        $bill = $this->createBill($this->agentBill());

        $this->actingAs($this->user)->getJson("/api/v1/bills/{$bill->id}")
            ->assertOk()
            ->assertJsonPath('data.lines.0.grn_id', $this->grnId)
            ->assertJsonPath('data.lines.0.grn_no', 'GRN-2026-0101')
            ->assertJsonPath('data.lines.0.treatment', 'landed_cost')
            ->assertJsonPath('data.lines.2.treatment', 'recoverable_tax')
            ->assertJsonPath('data.lines.2.cost_amount', '0.00');
    }

    public function test_an_expense_line_cannot_link_to_a_grn(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload(['lines' => [
            $this->line('DEMURRAGE', '5000.00', ['ledger_account_id' => $this->expense->id, 'grn_id' => $this->grnId]),
        ]]))->assertUnprocessable()->assertJsonValidationErrors('lines.0.grn_id');
    }

    public function test_a_line_without_a_charge_type_cannot_link_to_a_grn(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload(['lines' => [
            ['ledger_account_id' => $this->expense->id, 'amount' => '100.00', 'grn_id' => $this->grnId],
        ]]))->assertUnprocessable()->assertJsonValidationErrors('lines.0.grn_id');
    }

    public function test_a_draft_or_missing_grn_is_refused(): void
    {
        $draft = $this->makeGrn('GRN-2026-0102', 'draft');

        foreach ([$draft, 999999] as $grnId) {
            $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload(['lines' => [
                $this->line('CLEARING', '100.00', ['grn_id' => $grnId]),
            ]]))->assertUnprocessable()->assertJsonValidationErrors('lines.0.grn_id');
        }
    }

    public function test_with_inventory_off_no_grn_can_be_linked(): void
    {
        app(SettingsService::class)->set('module.inventory', false);

        $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload(['lines' => [
            $this->line('CLEARING', '100.00', ['grn_id' => $this->grnId]),
        ]]))->assertUnprocessable()->assertJsonValidationErrors('lines.0.grn_id');

        $this->actingAs($this->user)->getJson('/api/v1/bills/options')
            ->assertOk()->assertJsonPath('data.grn_link_available', false);
    }

    public function test_import_vat_takes_no_tax_code(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload(['lines' => [
            $this->line('IMPORT_VAT', '680000.00', ['tax_code_id' => $this->taxCode('VAT-IN')]),
        ]]))->assertUnprocessable()->assertJsonValidationErrors('lines.0.tax_code_id');
    }

    public function test_an_inactive_charge_type_is_refused(): void
    {
        ChargeType::where('code', 'CLEARING')->update(['is_active' => false]);

        $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload(['lines' => [
            $this->line('CLEARING', '100.00'),
        ]]))->assertUnprocessable()->assertJsonValidationErrors('lines.0.charge_type_id');
    }

    public function test_confirm_refuses_a_grn_that_is_no_longer_confirmed(): void
    {
        $bill = $this->createBill($this->agentBill());
        DB::table('inv_goods_received_notes')->where('id', $this->grnId)->update(['status' => 'cancelled']);

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/confirm")
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->assertSame(BillStatus::Draft, $bill->fresh()->status);
    }

    public function test_the_grn_picker_lists_confirmed_grns_only(): void
    {
        $this->makeGrn('GRN-2026-0103', 'draft');

        $this->actingAs($this->user)->getJson('/api/v1/bills/grn-options?search=GRN-2026')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.grn_no', 'GRN-2026-0101');
    }

    public function test_options_carry_the_active_charge_types(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/bills/options')->assertOk();

        $codes = collect($response->json('data.charge_types'))->pluck('code')->all();
        $this->assertContains('IMPORT_VAT', $codes);
        $this->assertTrue($response->json('data.grn_link_available'));
        $this->assertTrue($response->json('data.vat_active'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * The clearing agent's bill from the walkthrough: fee 40,000 + VAT,
     * duty paid for us 477,000, the CusDec's import VAT 680,000.
     *
     * @return array<string, mixed>
     */
    private function agentBill(): array
    {
        return [
            'document_no' => 'AGT-5521',
            'lines'       => [
                $this->line('CLEARING', '40000.00', ['tax_code_id' => $this->taxCode('VAT-IN'), 'grn_id' => $this->grnId]),
                $this->line('CUSTOMS_DUTY', '477000.00', ['grn_id' => $this->grnId]),
                $this->line('IMPORT_VAT', '680000.00', ['grn_id' => $this->grnId]),
            ],
        ];
    }

    /**
     * @param  array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function line(string $chargeCode, string $amount, array $extra = []): array
    {
        $type = ChargeType::where('code', $chargeCode)->firstOrFail();

        return $extra + [
            'charge_type_id'    => $type->id,
            'ledger_account_id' => $type->ledger_account_id ?? $this->expense->id,
            'amount'            => $amount,
        ];
    }

    private function taxCode(string $code): int
    {
        return (int) TaxCode::where('code', $code)->value('id');
    }

    private function makeGrn(string $grnNo, string $status): int
    {
        return (int) DB::table('inv_goods_received_notes')->insertGetId([
            'grn_no'       => $grnNo,
            'supplier_id'  => $this->supplier->id,
            'grn_date'     => '2026-10-15',
            'status'       => $status,
            'total_amount' => 3000000,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /** @return array<int|string, float> account id (or role for mapped accounts) => debit minus credit */
    private function journal(Bill $bill): array
    {
        $roles = AccountDefault::whereNull('company_id')->pluck('rule_key', 'ledger_account_id');

        return JournalEntry::with('lines')->forSource(PostingRole::SOURCE_BILL, $bill->id)->sole()
            ->lines
            ->groupBy(fn ($l) => $roles[$l->ledger_account_id] ?? $l->ledger_account_id)
            ->map(fn ($lines) => round((float) $lines->sum(fn ($l) => (float) $l->debit - (float) $l->credit), 2))
            ->all();
    }
}
