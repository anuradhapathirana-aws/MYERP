<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contracts\JournalPoster;
use App\Services\SettingsService;
use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use App\Support\Journal\NullJournalPoster;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Database\Seeders\FinanceChartOfAccountsSeeder;
use Modules\Finance\Database\Seeders\FinanceGlAccountsSeeder;
use Modules\Finance\DTOs\LedgerAccountData;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Enums\CashBookType;
use Modules\Finance\Enums\JournalStatus;
use Modules\Finance\Enums\JournalType;
use Modules\Finance\Models\AccountDefault;
use Modules\Finance\Models\ControlAccount;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\AccountResolver;
use Modules\Finance\Services\JournalPostingService;
use Modules\Finance\Services\LedgerAccountService;
use Tests\TestCase;

/**
 * The general ledger engine.
 *
 * These tests pin the rules that make the ledger trustworthy: a journal always
 * balances, a role always resolves to the account it was mapped to, a document
 * can never post twice, a posted entry is only ever undone by a mirror image,
 * and nothing at all happens for a client who did not buy Finance.
 */
class JournalPostingTest extends TestCase
{
    use RefreshDatabase;

    private JournalPostingService $posting;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.finance', true);

        // Seeding rather than hand-building the chart means these tests also
        // prove the two seeders produce a chart the engine can actually post to.
        $this->seed(FinanceChartOfAccountsSeeder::class);
        $this->seed(FinanceGlAccountsSeeder::class);

        $this->posting = app(JournalPostingService::class);
        $this->immediateMode();
    }

    // ── The happy path ───────────────────────────────────────────────────────

    public function test_a_balanced_journal_posts_with_every_line(): void
    {
        $cash = $this->fundSource('Sampath Bank Current', companyId: 1);

        $journalNo = $this->posting->post(new JournalRequest(
            sourceType:  'supplier_payment',
            sourceId:    42,
            journalDate: new DateTimeImmutable('2026-04-12'),
            narration:   'PAY-0001 to ABC Textiles',
            lines:       [
                JournalLineRequest::debit(
                    AccountRole::AccountsPayable->value, 100000,
                    partyType: 'supplier', partyId: 7, sourceType: 'grn', sourceId: 99,
                ),
                JournalLineRequest::credit($cash->id, 95000),
                JournalLineRequest::credit(AccountRole::DiscountReceived->value, 5000),
            ],
        ));

        $this->assertSame('JV-2026-0001', $journalNo, 'The number is sequenced within the posting year.');

        $entry = JournalEntry::with('lines')->firstWhere('journal_no', $journalNo);

        $this->assertSame(JournalStatus::Posted, $entry->status);
        $this->assertSame(JournalType::Auto, $entry->journal_type);
        $this->assertSame('supplier_payment', $entry->source_type);
        $this->assertSame(42, $entry->source_id);
        $this->assertSame('100000.0000', $entry->total_debit);
        $this->assertSame('100000.0000', $entry->total_credit);
        $this->assertTrue($entry->isBalanced());
        $this->assertCount(3, $entry->lines);

        // The payables line keeps the subledger tags, which is what makes
        // "what do we owe this supplier" answerable without an account per
        // supplier.
        $payables = $entry->lines->firstWhere('party_type', 'supplier');
        $this->assertSame(7, $payables->party_id);
        $this->assertSame('grn', $payables->source_type);
        $this->assertSame(99, $payables->source_id);
        $this->assertSame(
            $this->mappedAccountId(AccountRole::AccountsPayable),
            $payables->ledger_account_id,
            'The role must resolve to the account the seeder mapped it to.',
        );

        // The company was never passed in — it came off the bank account.
        $this->assertSame(1, $entry->company_id);
    }

    public function test_line_numbers_are_sequential_and_dimensions_are_copied_down(): void
    {
        $this->postSimple(locationId: 3, companyId: 1);

        $entry = JournalEntry::with('lines')->first();

        $this->assertSame([1, 2], $entry->lines->pluck('line_no')->all());

        foreach ($entry->lines as $line) {
            $this->assertSame(3, $line->location_id, 'The header location is copied onto every line.');
            $this->assertSame(1, $line->company_id);
        }
    }

    // ── Balance is not negotiable ────────────────────────────────────────────

    public function test_an_unbalanced_journal_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not balance');

        $this->posting->post(new JournalRequest(
            sourceType:  'invoice',
            sourceId:    1,
            journalDate: new DateTimeImmutable('2026-04-12'),
            journalType: JournalRequest::TYPE_GENERAL,
            lines:       [
                JournalLineRequest::debit(AccountRole::AccountsReceivable->value, 100000),
                JournalLineRequest::credit(AccountRole::SalesRevenue->value, 90000),
            ],
        ));

        $this->assertDatabaseCount('fin_journal_entries', 0);
    }

    public function test_a_line_cannot_carry_both_a_debit_and_a_credit(): void
    {
        // Unreachable by construction: the only ways to build a line are
        // debit() and credit(), so a two-sided row cannot be expressed at all.
        $line = JournalLineRequest::debit(AccountRole::Purchases->value, 500);

        $this->assertSame(500.0, $line->debit);
        $this->assertSame(0.0, $line->credit);
    }

    public function test_a_zero_amount_line_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-zero');

        JournalLineRequest::debit(AccountRole::Purchases->value, 0);
    }

    public function test_a_single_line_journal_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least two lines');

        new JournalRequest(
            sourceType:  'grn',
            sourceId:    1,
            journalDate: new DateTimeImmutable('2026-04-12'),
            lines:       [JournalLineRequest::debit(AccountRole::Purchases->value, 100)],
        );
    }

    // ── Idempotency, enforced by the database ────────────────────────────────

    public function test_the_same_document_can_never_post_twice(): void
    {
        $this->postSimple(sourceId: 500);

        $this->expectException(QueryException::class);

        $this->postSimple(sourceId: 500);
    }

    public function test_different_documents_of_the_same_kind_both_post(): void
    {
        $this->postSimple(sourceId: 500);
        $this->postSimple(sourceId: 501);

        $this->assertDatabaseCount('fin_journal_entries', 2);
    }

    /**
     * Manual vouchers carry no source at all, and the unique index must not
     * treat two NULLs as a duplicate — otherwise an accountant could post
     * exactly one journal by hand, ever.
     */
    public function test_manual_vouchers_are_not_constrained_by_the_source_index(): void
    {
        foreach (range(1, 3) as $i) {
            $this->posting->post(new JournalRequest(
                sourceType:  JournalRequest::SOURCE_MANUAL,
                sourceId:    null,
                journalDate: new DateTimeImmutable('2026-04-30'),
                journalType: JournalRequest::TYPE_GENERAL,
                narration:   "Depreciation month {$i}",
                lines:       [
                    JournalLineRequest::debit(AccountRole::Purchases->value, 8000),
                    JournalLineRequest::credit(AccountRole::AccountsPayable->value, 8000),
                ],
            ));
        }

        $this->assertDatabaseCount('fin_journal_entries', 3);
    }

    // ── Review mode ──────────────────────────────────────────────────────────

    public function test_review_mode_holds_an_automatic_posting_as_a_draft(): void
    {
        app(SettingsService::class)->set(
            JournalPostingService::SETTING_POSTING_MODE,
            JournalPostingService::MODE_REVIEW,
        );

        $this->postSimple();

        $entry = JournalEntry::first();

        $this->assertSame(JournalStatus::Draft, $entry->status);
        $this->assertNull($entry->posted_at);
    }

    public function test_review_mode_does_not_hold_a_reversal(): void
    {
        $this->postSimple(sourceId: 77);

        app(SettingsService::class)->set(
            JournalPostingService::SETTING_POSTING_MODE,
            JournalPostingService::MODE_REVIEW,
        );

        $this->posting->reverseDocument('supplier_payment', 77);

        $reversal = JournalEntry::where('journal_type', JournalType::Reversal)->first();

        $this->assertSame(
            JournalStatus::Posted,
            $reversal->status,
            'A held reversal would leave the original posting standing against a voided document.',
        );
    }

    // ── Reversal ─────────────────────────────────────────────────────────────

    public function test_a_reversal_mirrors_every_line_and_retires_the_original(): void
    {
        $cash = $this->fundSource('Cash Account', companyId: 1);

        $this->posting->post(new JournalRequest(
            sourceType:  'supplier_payment',
            sourceId:    88,
            journalDate: new DateTimeImmutable('2026-04-12'),
            lines:       [
                JournalLineRequest::debit(
                    AccountRole::AccountsPayable->value, 60000,
                    partyType: 'supplier', partyId: 8, sourceType: 'grn', sourceId: 102,
                ),
                JournalLineRequest::credit($cash->id, 60000),
            ],
        ));

        $reversalNo = $this->posting->reverseDocument('supplier_payment', 88, 'Voided - wrong amount');

        $this->assertSame('JV-2026-0002', $reversalNo);

        $original = JournalEntry::where('source_id', 88)->firstOrFail();
        $reversal = JournalEntry::with('lines')->firstWhere('journal_no', $reversalNo);

        $this->assertSame(JournalStatus::Reversed, $original->status);
        $this->assertSame($reversal->id, $original->reversed_by_journal_id);
        $this->assertSame($original->id, $reversal->reverses_journal_id);

        // source_type stays null on the reversal so it cannot collide with the
        // unique index on the entry it reverses.
        $this->assertNull($reversal->source_type);
        $this->assertSame(JournalType::Reversal, $reversal->journal_type);

        // Each side swapped, account and subledger tags preserved.
        $payables = $reversal->lines->firstWhere('party_type', 'supplier');
        $this->assertSame('0.0000', $payables->debit);
        $this->assertSame('60000.0000', $payables->credit);
        $this->assertSame(8, $payables->party_id);
        $this->assertSame(102, $payables->source_id);

        // The two together net to nothing, which is the whole point.
        $this->assertSame(0.0, $this->accountBalance($this->mappedAccountId(AccountRole::AccountsPayable)));
    }

    /**
     * The subtle rule, pinned.
     *
     * Reversing ADDS a mirror journal rather than retracting the original, so
     * both have to count or the reversal inverts the very balance it was meant
     * to cancel. The last assertion records what the wrong filter would give,
     * so nobody "tidies up" inLedger() back to posted() later.
     */
    public function test_a_reversed_journal_still_counts_towards_balances(): void
    {
        $cash     = $this->fundSource('Cash Account', companyId: 1);
        $payables = $this->mappedAccountId(AccountRole::AccountsPayable);

        $this->posting->post(new JournalRequest(
            sourceType:  'supplier_payment',
            sourceId:    88,
            journalDate: new DateTimeImmutable('2026-04-12'),
            lines:       [
                JournalLineRequest::debit(AccountRole::AccountsPayable->value, 60000),
                JournalLineRequest::credit($cash->id, 60000),
            ],
        ));

        $this->assertSame(60000.0, $this->accountBalance($payables));

        $this->posting->reverseDocument('supplier_payment', 88);

        $this->assertSame(
            0.0,
            $this->accountBalance($payables),
            'The original and its mirror must cancel exactly.',
        );

        $postedOnly = \Modules\Finance\Models\JournalLine::query()
            ->whereHas('entry', fn ($q) => $q->posted())
            ->where('ledger_account_id', $payables)
            ->get(['debit', 'credit'])
            ->sum(fn ($l): float => (float) $l->debit - (float) $l->credit);

        $this->assertSame(
            -60000.0,
            round($postedOnly, 2),
            'Counting only Posted leaves the reversal standing alone and flips the sign. '
            . 'This is exactly why reports must use inLedger(), not posted().',
        );
    }

    public function test_reversing_a_document_that_never_posted_is_harmless(): void
    {
        $this->assertNull($this->posting->reverseDocument('supplier_payment', 999));
        $this->assertDatabaseCount('fin_journal_entries', 0);
    }

    public function test_a_document_cannot_be_reversed_twice(): void
    {
        $this->postSimple(sourceId: 55);

        $this->assertNotNull($this->posting->reverseDocument('supplier_payment', 55));
        $this->assertNull(
            $this->posting->reverseDocument('supplier_payment', 55),
            'The original is no longer Posted, so there is nothing left to reverse.',
        );

        $this->assertDatabaseCount('fin_journal_entries', 2);
    }

    // ── Mapping failures must be loud ────────────────────────────────────────

    public function test_an_unmapped_role_stops_the_posting(): void
    {
        AccountDefault::where('rule_key', AccountRole::DiscountReceived->value)->delete();
        app(AccountResolver::class)->flush();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No ledger account is mapped to Discount Received');

        $this->posting->post(new JournalRequest(
            sourceType:  'supplier_payment',
            sourceId:    1,
            journalDate: new DateTimeImmutable('2026-04-12'),
            lines:       [
                JournalLineRequest::debit(AccountRole::AccountsPayable->value, 5000),
                JournalLineRequest::credit(AccountRole::DiscountReceived->value, 5000),
            ],
        ));
    }

    public function test_a_mapped_but_inactive_account_stops_the_posting(): void
    {
        LedgerAccount::whereKey($this->mappedAccountId(AccountRole::AccountsPayable))
            ->update(['is_active' => false]);
        app(AccountResolver::class)->flush();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('is inactive');

        $this->postSimple();
    }

    public function test_an_unknown_role_key_stops_the_posting(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unknown posting role');

        $this->posting->post(new JournalRequest(
            sourceType:  'grn',
            sourceId:    1,
            journalDate: new DateTimeImmutable('2026-04-12'),
            lines:       [
                JournalLineRequest::debit('accounts_payabel', 100),
                JournalLineRequest::credit(AccountRole::Purchases->value, 100),
            ],
        ));
    }

    // ── Rounding ─────────────────────────────────────────────────────────────

    public function test_sub_rupee_residue_lands_on_the_rounding_account(): void
    {
        $cash = $this->fundSource('Cash Account', companyId: 1);

        // Two cents out: more than the 1c tolerance, well inside the 1 rupee
        // ceiling. An automatic posting may absorb it.
        $this->posting->post(new JournalRequest(
            sourceType:  'customer_receipt',
            sourceId:    1,
            journalDate: new DateTimeImmutable('2026-04-22'),
            lines:       [
                JournalLineRequest::debit($cash->id, 100.00),
                JournalLineRequest::credit(AccountRole::AccountsReceivable->value, 99.98),
            ],
        ));

        $entry = JournalEntry::with('lines')->first();

        $this->assertCount(3, $entry->lines, 'A rounding line was added.');
        $this->assertTrue($entry->isBalanced());

        $rounding = $entry->lines->firstWhere(
            'ledger_account_id',
            $this->mappedAccountId(AccountRole::RoundingDifference),
        );

        $this->assertNotNull($rounding);
        $this->assertSame('0.0200', $rounding->credit);
    }

    public function test_a_hand_entered_voucher_gets_no_rounding_licence(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not balance');

        // Same two cents, but typed by a person. The form already blocks
        // saving until the difference reads zero, so residue here means the
        // user got it wrong and must be told.
        $this->posting->post(new JournalRequest(
            sourceType:  JournalRequest::SOURCE_MANUAL,
            sourceId:    null,
            journalDate: new DateTimeImmutable('2026-04-30'),
            journalType: JournalRequest::TYPE_GENERAL,
            lines:       [
                JournalLineRequest::debit(AccountRole::Purchases->value, 100.00),
                JournalLineRequest::credit(AccountRole::AccountsPayable->value, 99.98),
            ],
        ));
    }

    // ── Company derivation ───────────────────────────────────────────────────

    public function test_a_document_spanning_two_companies_is_rejected(): void
    {
        $a = $this->fundSource('Company A Bank', companyId: 1);
        $b = $this->fundSource('Company B Bank', companyId: 2);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('more than one');

        $this->posting->post(new JournalRequest(
            sourceType:  'supplier_payment',
            sourceId:    1,
            journalDate: new DateTimeImmutable('2026-04-12'),
            lines:       [
                JournalLineRequest::debit(AccountRole::AccountsPayable->value, 100),
                JournalLineRequest::credit($a->id, 60),
                JournalLineRequest::credit($b->id, 40),
            ],
        ));
    }

    public function test_two_accounts_of_the_same_company_are_fine(): void
    {
        $a = $this->fundSource('Sampath Current', companyId: 1);
        $b = $this->fundSource('Commercial Savings', companyId: 1);

        $this->posting->post(new JournalRequest(
            sourceType:  'supplier_payment',
            sourceId:    1,
            journalDate: new DateTimeImmutable('2026-04-12'),
            lines:       [
                JournalLineRequest::debit(AccountRole::AccountsPayable->value, 100),
                JournalLineRequest::credit($a->id, 60),
                JournalLineRequest::credit($b->id, 40),
            ],
        ));

        $this->assertSame(1, JournalEntry::first()->company_id);
    }

    // ── The independence proof ───────────────────────────────────────────────

    /**
     * A client who has the module files but never bought Finance. Their
     * documents must keep working and nothing may reach the ledger.
     */
    public function test_nothing_posts_when_finance_is_not_entitled(): void
    {
        app(SettingsService::class)->set('module.finance', false);

        $journalNo = $this->postSimple();

        $this->assertNull($journalNo);
        $this->assertDatabaseCount('fin_journal_entries', 0);
        $this->assertFalse($this->posting->hasPostingFor('supplier_payment', 42));
        $this->assertNull($this->posting->reverseDocument('supplier_payment', 42));
    }

    public function test_the_core_default_is_a_no_op_poster(): void
    {
        // Proves the contract core ships: an Inventory-only installation never
        // loads FinanceServiceProvider, so this is what answers there.
        $this->assertNull((new NullJournalPoster())->post(new JournalRequest(
            sourceType:  'grn',
            sourceId:    1,
            journalDate: new DateTimeImmutable('2026-04-12'),
            lines:       [
                JournalLineRequest::debit(AccountRole::Purchases->value, 100),
                JournalLineRequest::credit(AccountRole::AccountsPayable->value, 100),
            ],
        )));

        $this->assertFalse((new NullJournalPoster())->hasPostingFor('grn', 1));
    }

    public function test_the_container_hands_finance_the_contract(): void
    {
        $this->assertInstanceOf(JournalPostingService::class, app(JournalPoster::class));
    }

    // ── Posting status ───────────────────────────────────────────────────────

    public function test_has_posting_for_reports_a_live_journal(): void
    {
        $this->assertFalse($this->posting->hasPostingFor('supplier_payment', 42));

        $this->postSimple(sourceId: 42);

        $this->assertTrue($this->posting->hasPostingFor('supplier_payment', 42));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function immediateMode(): void
    {
        app(SettingsService::class)->set(
            JournalPostingService::SETTING_POSTING_MODE,
            JournalPostingService::MODE_IMMEDIATE,
        );
    }

    private function postSimple(int $sourceId = 42, ?int $locationId = null, ?int $companyId = null): ?string
    {
        return $this->posting->post(new JournalRequest(
            sourceType:  'supplier_payment',
            sourceId:    $sourceId,
            journalDate: new DateTimeImmutable('2026-04-12'),
            lines:       [
                JournalLineRequest::debit(AccountRole::AccountsPayable->value, 1000),
                JournalLineRequest::credit(AccountRole::Purchases->value, 1000),
            ],
            companyId:   $companyId,
            locationId:  $locationId,
        ));
    }

    /** A bank ledger account, which is what a Pay From dropdown would offer. */
    private function fundSource(string $name, int $companyId): LedgerAccount
    {
        $control = ControlAccount::where('control_account_name', 'Cash & Cash Equivalents')->firstOrFail();

        return app(LedgerAccountService::class)->create(new LedgerAccountData(
            control_account_id:  $control->id,
            ledger_account_name: $name,
            description:         null,
            is_active:           true,
            cash_book_type:      CashBookType::Bank,
            company_id:          $companyId,
            allows_cash:         false,
            allows_cheque:       true,
            bank_id:             null,
            bank_branch_id:      null,
            bank_account_no:     null,
        ));
    }

    private function mappedAccountId(AccountRole $role): int
    {
        return (int) AccountDefault::where('rule_key', $role->value)->value('ledger_account_id');
    }

    /** Debits minus credits across every line that counts, for one account. */
    private function accountBalance(int $accountId): float
    {
        $lines = \Modules\Finance\Models\JournalLine::inLedger()
            ->where('ledger_account_id', $accountId)
            ->get(['debit', 'credit']);

        return round(
            $lines->sum(fn ($l): float => (float) $l->debit - (float) $l->credit),
            2,
        );
    }
}
