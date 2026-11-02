<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Support\Journal\PostingRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Enums\BillStatus;
use Modules\Finance\Enums\JournalStatus;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Services\FinanceControlService;
use Tests\Feature\Finance\Concerns\BillFixtures;
use Tests\TestCase;

/**
 * Nothing may be posted or reversed on or before the lock date, from any
 * screen. Today is 2026-10-26 in these tests.
 */
class PeriodLockTest extends TestCase
{
    use BillFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBills();
    }

    private function lock(string $date): void
    {
        $this->actingAs($this->user)->putJson('/api/v1/finance-settings/lock-date', ['lock_date' => $date])
            ->assertOk()->assertJsonPath('data.lock_date', $date);
    }

    public function test_without_a_lock_nothing_is_blocked(): void
    {
        $this->assertNull(app(FinanceControlService::class)->lockDate());

        $this->confirmedBill(['transaction_date' => '2025-01-15', 'due_date' => '2025-02-14', 'document_date' => null]);

        $this->assertSame(1, JournalEntry::count());
    }

    public function test_a_bill_dated_in_a_closed_period_cannot_be_saved(): void
    {
        $this->lock('2026-09-30');

        $this->actingAs($this->user)->postJson('/api/v1/bills', $this->billPayload([
            'transaction_date' => '2026-09-30', 'document_date' => null, 'due_date' => '2026-10-30',
        ]))->assertUnprocessable()->assertJsonValidationErrors('transaction_date');

        // The day after the lock is open.
        $this->createBill(['transaction_date' => '2026-10-01', 'document_date' => null, 'due_date' => '2026-10-31']);
    }

    public function test_a_draft_saved_before_the_lock_cannot_be_confirmed_after_it(): void
    {
        $bill = $this->createBill(['transaction_date' => '2026-09-25', 'document_date' => null, 'due_date' => '2026-10-25']);

        $this->lock('2026-09-30');

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/confirm")
            ->assertUnprocessable()->assertJsonValidationErrors('transaction_date');

        $this->assertSame(BillStatus::Draft, $bill->fresh()->status);
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_a_void_cannot_reverse_into_a_closed_period(): void
    {
        $bill = $this->confirmedBill(['transaction_date' => '2026-09-20', 'document_date' => null, 'due_date' => '2026-10-20']);

        $this->lock('2026-09-30');

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/void", [
            'reason' => 'Wrong', 'reversal_date' => '2026-09-30',
        ])->assertUnprocessable()->assertJsonValidationErrors('reversal_date');

        // Reversing in the open period works, and the September entry stays untouched.
        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/void", [
            'reason' => 'Wrong', 'reversal_date' => '2026-10-01',
        ])->assertOk();

        $original = JournalEntry::forSource(PostingRole::SOURCE_BILL, $bill->id)->firstOrFail();
        $this->assertSame('2026-09-20', $original->journal_date->toDateString());
        $this->assertSame('2026-10-01', JournalEntry::findOrFail($original->reversed_by_journal_id)->journal_date->toDateString());
    }

    public function test_a_manual_voucher_in_a_closed_period_is_refused(): void
    {
        $this->lock('2026-09-30');

        $this->actingAs($this->user)->postJson('/api/v1/journal-entries', [
            'journal_date' => '2026-09-15',
            'journal_type' => 'general',
            'lines'        => [
                ['ledger_account_id' => $this->expense->id, 'debit' => 100],
                ['ledger_account_id' => $this->mapped(\Modules\Finance\Enums\AccountRole::AccountsPayable), 'credit' => 100],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('journal_date');
    }

    public function test_the_posting_service_itself_refuses_a_closed_date(): void
    {
        $this->lock('2026-09-30');

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(\App\Contracts\JournalPoster::class)->post(new \App\Support\Journal\JournalRequest(
            sourceType:  PostingRole::SOURCE_GRN,
            sourceId:    1,
            journalDate: new \DateTimeImmutable('2026-09-10'),
            lines:       [
                \App\Support\Journal\JournalLineRequest::debit($this->expense->id, 10.0),
                \App\Support\Journal\JournalLineRequest::credit(PostingRole::ACCOUNTS_PAYABLE, 10.0),
            ],
        ));
    }

    public function test_a_draft_journal_in_a_closed_period_cannot_be_posted(): void
    {
        app(\App\Services\SettingsService::class)->set(
            \Modules\Finance\Services\JournalPostingService::SETTING_POSTING_MODE,
            \Modules\Finance\Services\JournalPostingService::MODE_REVIEW,
        );

        $bill  = $this->confirmedBill(['transaction_date' => '2026-09-20', 'document_date' => null, 'due_date' => '2026-10-20']);
        $draft = JournalEntry::forSource(PostingRole::SOURCE_BILL, $bill->id)->firstOrFail();
        $this->assertSame(JournalStatus::Draft, $draft->status);

        $this->lock('2026-09-30');

        $this->actingAs($this->user)->postJson("/api/v1/journal-entries/{$draft->id}/post")
            ->assertUnprocessable()->assertJsonValidationErrors('journal_date');
    }

    public function test_the_lock_date_cannot_be_in_the_future(): void
    {
        $this->actingAs($this->user)->putJson('/api/v1/finance-settings/lock-date', ['lock_date' => '2026-10-27'])
            ->assertUnprocessable()->assertJsonValidationErrors('lock_date');
    }

    public function test_reopening_a_period_is_audited(): void
    {
        $this->lock('2026-09-30');
        $this->lock('2026-08-31');

        $events = AuditLog::where('auditable_type', 'finance_setting')->orderBy('id')->pluck('event')->all();
        $this->assertSame(['lock_date_changed', 'period_reopened'], $events);
    }

    public function test_only_settings_managers_can_move_the_lock(): void
    {
        $clerk = $this->makeUser(['view_bills', 'create_bills']);

        $this->actingAs($clerk)->putJson('/api/v1/finance-settings/lock-date', ['lock_date' => '2026-09-30'])
            ->assertForbidden();
    }
}
