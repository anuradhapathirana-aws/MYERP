<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Services\SettingsService;
use App\Support\Journal\PostingRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Enums\BillStatus;
use Modules\Finance\Enums\JournalStatus;
use Modules\Finance\Models\Bill;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Services\FinanceControlService;
use Modules\Finance\Services\JournalPostingService;
use Tests\Feature\Finance\Concerns\BillFixtures;
use Tests\TestCase;

/**
 * Bill Entry: enter -> confirm (post) -> void (reverse on a chosen date).
 */
class BillEntryTest extends TestCase
{
    use BillFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBills();
    }

    // ── Create ───────────────────────────────────────────────────────────────

    public function test_a_bill_is_created_as_a_draft_with_a_sequenced_number(): void
    {
        $first  = $this->createBill();
        $second = $this->createBill(['document_no' => 'CEB-1002']);

        $this->assertSame('BILL-2026-0001', $first->bill_no);
        $this->assertSame('BILL-2026-0002', $second->bill_no);
        $this->assertSame(BillStatus::Draft, $first->status);
        $this->assertSame('15000.50', (string) $first->total_amount);
        $this->assertCount(1, $first->lines);
        $this->assertSame($this->expense->id, $first->lines->first()->ledger_account_id);
    }

    public function test_the_next_number_preview_does_not_consume_a_number(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/bills/next-no')
            ->assertOk()->assertJsonPath('data.bill_no', 'BILL-2026-0001');
        $this->actingAs($this->user)->getJson('/api/v1/bills/next-no')
            ->assertJsonPath('data.bill_no', 'BILL-2026-0001');

        $this->assertSame('BILL-2026-0001', $this->createBill()->bill_no);
    }

    public function test_a_deleted_draft_number_is_never_reused(): void
    {
        $draft = $this->createBill();
        $this->actingAs($this->user)->deleteJson("/api/v1/bills/{$draft->id}")->assertNoContent();

        $this->assertSame('BILL-2026-0002', $this->createBill(['document_no' => 'CEB-2'])->bill_no);
    }

    public function test_supplier_defaults_give_the_due_date_from_the_credit_period(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/v1/bills/supplier-defaults/{$this->supplier->id}?transaction_date=2026-10-20")
            ->assertOk()
            ->assertJsonPath('data.due_date', '2026-11-19')
            ->assertJsonPath('data.credit_period', 30);
    }

    public function test_the_same_supplier_invoice_cannot_be_entered_twice_in_any_spelling(): void
    {
        $this->createBill(['document_no' => 'CEB-1001']);

        foreach (['ceb-1001', ' CEB 1001 ', 'CEB1001', 'ceb/1001'] as $variant) {
            $this->actingAs($this->user)
                ->postJson('/api/v1/bills', $this->billPayload(['document_no' => $variant]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('document_no');
        }
    }

    public function test_a_voided_bill_frees_its_supplier_invoice_number(): void
    {
        $bill = $this->confirmedBill();

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/void", [
            'reason' => 'Entered twice', 'reversal_date' => '2026-10-26',
        ])->assertOk();

        $this->createBill(['document_no' => 'CEB-1001']);
        $this->assertSame(2, Bill::count());
    }

    // ── Confirm ──────────────────────────────────────────────────────────────

    public function test_confirming_posts_dr_expense_and_cr_payables_against_the_supplier(): void
    {
        $bill = $this->confirmedBill();

        $this->assertSame(BillStatus::Confirmed, $bill->status);
        $this->assertNotNull($bill->journal_no);

        $entry = JournalEntry::with('lines')->forSource(PostingRole::SOURCE_BILL, $bill->id)->firstOrFail();

        $this->assertSame(JournalStatus::Posted, $entry->status);
        $this->assertSame('2026-10-20', $entry->journal_date->toDateString());
        $this->assertSame($this->companyA, $entry->company_id);
        $this->assertTrue($entry->isBalanced());
        $this->assertCount(2, $entry->lines);

        $debit = $entry->lines->firstWhere('ledger_account_id', $this->expense->id);
        $this->assertSame('15000.5000', $debit->debit);

        $credit = $entry->lines->firstWhere('ledger_account_id', $this->mapped(AccountRole::AccountsPayable));
        $this->assertSame('15000.5000', $credit->credit);
        $this->assertSame(PostingRole::PARTY_SUPPLIER, $credit->party_type);
        $this->assertSame($this->supplier->id, $credit->party_id);
        $this->assertSame(PostingRole::SOURCE_BILL, $credit->source_type);
        $this->assertSame($bill->id, $credit->source_id);
    }

    public function test_description_is_optional_and_the_journal_falls_back_to_the_invoice_no(): void
    {
        $bill = $this->confirmedBill(['description' => '']);

        $this->assertNull($bill->description);

        $debit = JournalEntry::with('lines')->forSource(PostingRole::SOURCE_BILL, $bill->id)->firstOrFail()
            ->lines->firstWhere('ledger_account_id', $this->expense->id);
        $this->assertSame('Supplier invoice CEB-1001', $debit->description);
    }

    public function test_confirm_rechecks_that_the_account_can_still_be_charged(): void
    {
        $bill = $this->createBill();

        // After the draft was saved, someone turns the expense account into a Bank.
        DB::table('fin_ledger_accounts')->where('id', $this->expense->id)->update(['cash_book_type' => 'bank']);

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines');

        $this->assertSame(BillStatus::Draft, $bill->fresh()->status);
        $this->assertSame(0, JournalEntry::forSource(PostingRole::SOURCE_BILL, $bill->id)->count());

        // Or moves it to another company.
        DB::table('fin_ledger_accounts')->where('id', $this->expense->id)
            ->update(['cash_book_type' => null, 'company_id' => $this->companyB]);

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines');

        DB::table('fin_ledger_accounts')->where('id', $this->expense->id)->update(['company_id' => null]);
        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/confirm")->assertOk();
    }

    public function test_a_create_that_loses_the_request_id_race_returns_the_winner(): void
    {
        $winner = $this->createBill();
        $retry  = (string) Str::uuid();

        // The twin request commits right after this one's "already made?"
        // lookup came back empty, so the insert hits the unique index.
        $fired = false;
        DB::listen(function ($query) use (&$fired, $winner, $retry): void {
            if (! $fired && str_contains($query->sql, 'from "fin_bills" where "request_id" = ?') && in_array($retry, $query->bindings, true)) {
                $fired = true;
                DB::table('fin_bills')->where('id', $winner->id)->update(['request_id' => $retry]);
            }
        });

        // Same invoice no as the winner: the retry of one submit, not a new bill.
        $this->actingAs($this->user)
            ->postJson('/api/v1/bills', $this->billPayload(['request_id' => $retry, 'document_no' => 'CEB-RACE']))
            ->assertCreated()
            ->assertJsonPath('data.id', $winner->id);

        $this->assertTrue($fired);
        $this->assertSame(1, Bill::where('request_id', $retry)->count());
    }

    public function test_confirming_twice_posts_only_one_journal(): void
    {
        $bill = $this->confirmedBill();

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(1, JournalEntry::forSource(PostingRole::SOURCE_BILL, $bill->id)->count());
    }

    public function test_review_mode_posts_the_bill_journal_as_a_draft(): void
    {
        app(SettingsService::class)->set(JournalPostingService::SETTING_POSTING_MODE, JournalPostingService::MODE_REVIEW);

        $bill = $this->confirmedBill();

        $entry = JournalEntry::forSource(PostingRole::SOURCE_BILL, $bill->id)->firstOrFail();
        $this->assertSame(JournalStatus::Draft, $entry->status);
    }

    public function test_maker_checker_blocks_the_creator_from_confirming_when_on(): void
    {
        app(FinanceControlService::class)->setMakerChecker(true);

        $bill = $this->createBill();

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $checker = $this->makeUser();
        $this->actingAs($checker)->postJson("/api/v1/bills/{$bill->id}/confirm")->assertOk();
    }

    public function test_maker_checker_off_lets_the_creator_confirm(): void
    {
        $bill = $this->createBill();

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/confirm")->assertOk();
    }

    // ── Edit / delete ────────────────────────────────────────────────────────

    public function test_a_draft_can_be_edited_and_the_change_is_audited(): void
    {
        $bill = $this->createBill();

        $this->actingAs($this->user)->putJson("/api/v1/bills/{$bill->id}", $this->billPayload([
            'description' => 'Electricity, corrected',
            'lines'       => [['ledger_account_id' => $this->expense->id, 'amount' => '16000']],
            'updated_at'  => $bill->updated_at->toISOString(),
        ]))->assertOk()->assertJsonPath('data.total_amount', '16000.00');

        $log = AuditLog::where('auditable_type', 'bill')->where('event', 'updated')->firstOrFail();
        $this->assertSame('Electricity, corrected', $log->new_values['description']);
        $this->assertSame('Electricity for October', $log->old_values['description']);
        $this->assertSame($this->user->id, $log->user_id);
    }

    public function test_a_stale_edit_is_refused_with_409(): void
    {
        $bill = $this->createBill();
        $seen = $bill->updated_at->toISOString();

        $this->travel(5)->seconds();

        // Someone else saves first.
        $this->actingAs($this->user)->putJson("/api/v1/bills/{$bill->id}", $this->billPayload([
            'description' => 'First editor', 'updated_at' => $seen,
        ]))->assertOk();

        $this->actingAs($this->user)->putJson("/api/v1/bills/{$bill->id}", $this->billPayload([
            'description' => 'Second editor', 'updated_at' => $seen,
        ]))->assertStatus(409);

        $this->assertSame('First editor', $bill->fresh()->description);
    }

    public function test_a_confirmed_bill_cannot_be_edited_or_deleted(): void
    {
        $bill = $this->confirmedBill();

        $this->actingAs($this->user)->putJson("/api/v1/bills/{$bill->id}", $this->billPayload(['description' => 'x']))
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->actingAs($this->user)->deleteJson("/api/v1/bills/{$bill->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    // ── Void ─────────────────────────────────────────────────────────────────

    public function test_voiding_reverses_the_journal_on_the_chosen_date(): void
    {
        $bill = $this->confirmedBill();

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/void", [
            'reason' => 'Wrong supplier', 'reversal_date' => '2026-10-22',
        ])->assertOk()->assertJsonPath('data.status', 'voided');

        $bill->refresh();
        $this->assertSame(BillStatus::Voided, $bill->status);
        $this->assertSame('2026-10-22', $bill->reversal_date->toDateString());

        $original = JournalEntry::forSource(PostingRole::SOURCE_BILL, $bill->id)->firstOrFail();
        $this->assertSame(JournalStatus::Reversed, $original->status);

        $reversal = JournalEntry::findOrFail($original->reversed_by_journal_id);
        $this->assertSame('2026-10-22', $reversal->journal_date->toDateString());
        $this->assertSame($bill->reversal_journal_no, $reversal->journal_no);
    }

    public function test_a_reversal_date_before_the_bill_or_in_the_future_is_refused(): void
    {
        $bill = $this->confirmedBill();

        foreach (['2026-10-19', '2026-10-27'] as $date) {
            $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/void", [
                'reason' => 'Wrong', 'reversal_date' => $date,
            ])->assertUnprocessable()->assertJsonValidationErrors('reversal_date');
        }

        $this->assertSame(BillStatus::Confirmed, $bill->fresh()->status);
    }

    public function test_void_needs_a_reason(): void
    {
        $bill = $this->confirmedBill();

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/void", ['reversal_date' => '2026-10-26'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_a_bill_with_payments_cannot_be_voided(): void
    {
        $bill = $this->confirmedBill();
        $bill->forceFill(['paid_amount' => '100.00'])->save();

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/void", [
            'reason' => 'Wrong', 'reversal_date' => '2026-10-26',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_a_draft_cannot_be_voided(): void
    {
        $bill = $this->createBill();

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/void", [
            'reason' => 'Wrong', 'reversal_date' => '2026-10-26',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    // ── Audit trail ──────────────────────────────────────────────────────────

    public function test_every_state_change_is_in_the_history(): void
    {
        $bill = $this->confirmedBill();

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/void", [
            'reason' => 'Duplicate', 'reversal_date' => '2026-10-26',
        ])->assertOk();

        $events = $this->actingAs($this->user)->getJson("/api/v1/bills/{$bill->id}/history")
            ->assertOk()->json('data.*.event');

        $this->assertSame(['voided', 'confirmed', 'created'], $events);
    }

    public function test_audit_rows_cannot_be_changed_or_deleted(): void
    {
        $this->createBill();
        $log = AuditLog::firstOrFail();

        try {
            $log->update(['event' => 'tampered']);
            $this->fail('An audit row was updated.');
        } catch (\LogicException) {
        }

        try {
            $log->delete();
            $this->fail('An audit row was deleted.');
        } catch (\LogicException) {
        }

        $this->assertSame('created', $log->fresh()->event);
    }
}
