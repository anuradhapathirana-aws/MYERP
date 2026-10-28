<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Services\CompanyAccessService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Finance\Enums\BillStatus;
use Modules\Finance\Enums\CashBookType;
use Modules\Finance\Models\Bill;
use Tests\Feature\Finance\Concerns\BillFixtures;
use Tests\TestCase;

/**
 * The security and validation controls on Bill Entry, one test per control.
 */
class BillSecurityTest extends TestCase
{
    use BillFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBills();
    }

    // ── Authorization ────────────────────────────────────────────────────────

    public function test_each_action_needs_its_own_permission(): void
    {
        $bill   = $this->createBill();
        $viewer = $this->makeUser(['view_bills']);

        $this->actingAs($viewer)->getJson('/api/v1/bills')->assertOk();
        $this->actingAs($viewer)->postJson('/api/v1/bills', $this->billPayload(['document_no' => 'X1']))->assertForbidden();
        $this->actingAs($viewer)->putJson("/api/v1/bills/{$bill->id}", $this->billPayload())->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/v1/bills/{$bill->id}/confirm")->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/v1/bills/{$bill->id}/void", [
            'reason' => 'x', 'reversal_date' => '2026-10-26',
        ])->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/v1/bills/{$bill->id}")->assertForbidden();

        $nobody = $this->makeUser([]);
        $this->actingAs($nobody)->getJson('/api/v1/bills')->assertForbidden();
    }

    public function test_bills_are_unreachable_when_finance_is_disabled(): void
    {
        app(SettingsService::class)->set('module.finance', false);

        $this->actingAs($this->user)->getJson('/api/v1/bills')->assertForbidden();
        $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload())->assertForbidden();
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/v1/bills')->assertUnauthorized();
    }

    // ── Mass assignment ──────────────────────────────────────────────────────

    public function test_server_owned_fields_sent_by_the_client_are_ignored(): void
    {
        $bill = $this->createBill([
            'bill_no'        => 'HACK-1',
            'status'         => 'confirmed',
            'payment_status' => 'paid',
            'paid_amount'    => '15000.50',
            'total_amount'   => '1.00',
            'created_by'     => 999,
            'journal_no'     => 'JV-FAKE',
        ]);

        $this->assertSame('BILL-2026-0001', $bill->bill_no);
        $this->assertSame(BillStatus::Draft, $bill->status);
        $this->assertSame('0.00', (string) $bill->paid_amount);
        $this->assertSame('15000.50', (string) $bill->total_amount);
        $this->assertSame($this->user->id, $bill->created_by);
        $this->assertNull($bill->journal_no);
    }

    // ── Linked records ───────────────────────────────────────────────────────

    public function test_bank_cash_party_income_and_inactive_accounts_are_refused(): void
    {
        $bank     = $this->ledger('Sampath Current', 'Cash & Cash Equivalents', CashBookType::Bank, $this->companyA);
        $income   = $this->ledger('Scrap Sales', 'Other Operating Income');
        $inactive = $this->ledger('Old Expense', 'Office & Establishment');
        $inactive->update(['is_active' => false]);
        $party = $this->ledger('Supplier Ledger', 'Office & Establishment');
        $party->forceFill(['party_type' => 'supplier', 'party_id' => $this->supplier->id])->save();

        foreach ([$bank, $income, $inactive, $party] as $i => $account) {
            $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload([
                'document_no' => 'ACC-' . $i,
                'lines'       => [['ledger_account_id' => $account->id]],
            ]))->assertUnprocessable()->assertJsonValidationErrors('lines.0.ledger_account_id');
        }
    }

    public function test_an_expense_account_owned_by_another_company_is_refused(): void
    {
        $other = $this->ledger('Beta Rent', 'Office & Establishment', null, $this->companyB);

        $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload([
            'lines' => [['ledger_account_id' => $other->id]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('lines.0.ledger_account_id');
    }

    public function test_a_location_of_another_company_is_refused(): void
    {
        $betaLocation = $this->makeLocation($this->companyB, 'BETA-01');

        $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload(['location_id' => $betaLocation]))
            ->assertUnprocessable()->assertJsonValidationErrors('location_id');

        $alphaLocation = $this->makeLocation($this->companyA, 'ALPHA-01');
        $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload(['location_id' => $alphaLocation]))
            ->assertCreated();
    }

    public function test_a_deleted_location_is_refused(): void
    {
        $location = $this->makeLocation($this->companyA, 'ALPHA-OLD');
        \Illuminate\Support\Facades\DB::table('inv_locations')->where('id', $location)->update(['deleted_at' => now()]);

        $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload(['location_id' => $location]))
            ->assertUnprocessable()->assertJsonValidationErrors('location_id');
    }

    public function test_an_unknown_supplier_is_refused(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload(['supplier_id' => 99999]))
            ->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
    }

    // ── Amounts and dates ────────────────────────────────────────────────────

    public function test_bad_amounts_are_refused(): void
    {
        foreach (['0', '-5', '10.555', '99999999999', 'abc'] as $i => $amount) {
            $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload([
                'document_no' => 'AMT-' . $i,
                'lines'       => [['amount' => $amount]],
            ]))->assertUnprocessable()->assertJsonValidationErrors('lines.0.amount');
        }
    }

    public function test_date_rules_are_enforced(): void
    {
        // Due before the transaction date.
        $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload(['due_date' => '2026-10-19']))
            ->assertUnprocessable()->assertJsonValidationErrors('due_date');

        // Supplier's document dated after our transaction date.
        $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload(['document_date' => '2026-10-21']))
            ->assertUnprocessable()->assertJsonValidationErrors('document_date');

        // More than a year ahead.
        $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload([
            'transaction_date' => '2027-11-01', 'due_date' => '2027-12-01',
        ]))->assertUnprocessable()->assertJsonValidationErrors('transaction_date');

        // Wrong format.
        $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload(['transaction_date' => '20/10/2026']))
            ->assertUnprocessable()->assertJsonValidationErrors('transaction_date');
    }

    // ── Text ─────────────────────────────────────────────────────────────────

    public function test_markup_is_stripped_and_lengths_are_capped(): void
    {
        $bill = $this->createBill([
            'description' => '<script>alert(1)</script>Electricity <b>Oct</b>',
            'document_no' => '<i>CEB-77</i>',
        ]);

        $this->assertSame('alert(1)Electricity Oct', $bill->description);
        $this->assertSame('CEB-77', $bill->document_no);

        $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload([
            'document_no' => 'LONG', 'description' => str_repeat('a', 1001),
        ]))->assertUnprocessable()->assertJsonValidationErrors('description');
    }

    // ── Idempotency ──────────────────────────────────────────────────────────

    public function test_a_repeated_request_id_returns_the_same_bill(): void
    {
        $requestId = (string) Str::uuid();

        $first  = $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload(['request_id' => $requestId]))
            ->assertCreated()->json('data.id');
        $second = $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload(['request_id' => $requestId]))
            ->assertCreated()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Bill::count());
    }

    // ── Listing ──────────────────────────────────────────────────────────────

    public function test_page_size_is_capped_and_unknown_sort_columns_are_ignored(): void
    {
        $this->createBill();

        $this->actingAs($this->user)->getJson('/api/v1/bills?per_page=10000')
            ->assertOk()->assertJsonPath('meta.per_page', 100);

        $this->actingAs($this->user)->getJson('/api/v1/bills?sort=password;drop table users&direction=sideways')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_resource_does_not_leak_internal_fields(): void
    {
        $bill = $this->createBill();

        $data = $this->actingAs($this->user)->getJson("/api/v1/bills/{$bill->id}")->assertOk()->json('data');

        $this->assertArrayNotHasKey('request_id', $data);
        $this->assertArrayNotHasKey('document_no_normalized', $data);
        $this->assertArrayNotHasKey('deleted_at', $data);
    }

    // ── Company access ───────────────────────────────────────────────────────

    public function test_a_restricted_user_cannot_see_another_companys_bill(): void
    {
        $alphaBill = $this->createBill();
        $betaBill  = $this->createBill(['company_id' => $this->companyB, 'document_no' => 'B-1']);

        $alphaOnly = $this->makeUser();
        app(CompanyAccessService::class)->sync($alphaOnly, [$this->companyA]);

        $this->actingAs($alphaOnly)->getJson('/api/v1/bills')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $alphaBill->id);

        // 404, not 403: the bill must not be seen to exist.
        $this->actingAs($alphaOnly)->getJson("/api/v1/bills/{$betaBill->id}")->assertNotFound();
        $this->actingAs($alphaOnly)->putJson("/api/v1/bills/{$betaBill->id}", $this->billPayload())->assertNotFound();
        $this->actingAs($alphaOnly)->postJson("/api/v1/bills/{$betaBill->id}/confirm")->assertNotFound();
        $this->actingAs($alphaOnly)->deleteJson("/api/v1/bills/{$betaBill->id}")->assertNotFound();
        $this->actingAs($alphaOnly)->getJson("/api/v1/bills/{$betaBill->id}/history")->assertNotFound();
    }

    public function test_a_restricted_user_cannot_create_for_another_company(): void
    {
        $alphaOnly = $this->makeUser();
        app(CompanyAccessService::class)->sync($alphaOnly, [$this->companyA]);

        $this->actingAs($alphaOnly)->postJson('/api/v1/bills', $this->billPayload(['company_id' => $this->companyB]))
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');
    }

    public function test_an_unrestricted_user_sees_every_company(): void
    {
        $this->createBill();
        $this->createBill(['company_id' => $this->companyB, 'document_no' => 'B-1']);

        $this->actingAs($this->makeUser())->getJson('/api/v1/bills')->assertOk()->assertJsonCount(2, 'data');
    }

    // ── Rate limiting ────────────────────────────────────────────────────────

    public function test_writes_are_rate_limited(): void
    {
        $bill = $this->createBill();

        // createBill used one of the 60; the next 59 are allowed.
        for ($i = 0; $i < 59; $i++) {
            $this->actingAs($this->user)->deleteJson('/api/v1/bills/999999')->assertNotFound();
        }

        $this->actingAs($this->user)->deleteJson("/api/v1/bills/{$bill->id}")->assertStatus(429);
    }
}
