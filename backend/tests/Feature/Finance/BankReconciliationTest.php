<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contracts\JournalPoster;
use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Finance\Enums\BankReconciliationStatus;
use Modules\Finance\Enums\CashBookType;
use Modules\Finance\Models\BankReconciliation;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\FinanceControlService;
use Modules\Finance\Services\JournalPostingService;
use Tests\Feature\Finance\Concerns\BillFixtures;
use Tests\TestCase;

/**
 * Bank Reconciliation: match a bank account's ledger lines to its statement.
 *
 * The bank movements are real journals posted through JournalPoster, carrying
 * cheque numbers on the bank line exactly as the payment documents now do.
 */
class BankReconciliationTest extends TestCase
{
    use BillFixtures;
    use RefreshDatabase;

    private LedgerAccount $bank;
    private LedgerAccount $income;

    protected function setUp(): void
    {
        parent::setUp();

        $this->billPermissions = [
            ...$this->billPermissions,
            'view_bank_reconciliations', 'create_bank_reconciliations', 'edit_bank_reconciliations',
            'delete_bank_reconciliations', 'post_bank_reconciliations', 'void_bank_reconciliations',
        ];

        $this->setUpBills();

        $this->bank   = $this->ledger('Sampath Current', 'Cash & Cash Equivalents', CashBookType::Bank, $this->companyA);
        $this->income = $this->ledger('Interest Received', 'Office & Establishment');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Post a money movement on the bank and return its bank journal line id. */
    private function move(float $amount, bool $in, string $date, ?string $chequeNo = null): int
    {
        $bankLine = ($in
            ? JournalLineRequest::debit($this->bank->id, $amount, description: 'Money in')
            : JournalLineRequest::credit($this->bank->id, $amount, description: 'Money out'))
            ->withInstrument($chequeNo ? 'cheque' : 'bank_transfer', $chequeNo, $chequeNo ? $date : null);

        $other = $in
            ? JournalLineRequest::credit($this->income->id, $amount)
            : JournalLineRequest::debit($this->expense->id, $amount);

        $journalNo = app(JournalPoster::class)->post(new JournalRequest(
            sourceType:  null,
            sourceId:    null,
            journalDate: CarbonImmutable::parse($date),
            lines:       [$bankLine, $other],
            journalType: JournalRequest::TYPE_GENERAL,
        ));

        return (int) JournalLine::whereHas('entry', fn ($q) => $q->where('journal_no', $journalNo))
            ->where('ledger_account_id', $this->bank->id)
            ->value('id');
    }

    /**
     * @param  array<int, string>   $lines journal_line_id => status
     * @param  array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $lines, string $statementBalance, array $overrides = []): array
    {
        return array_merge([
            'request_id'        => (string) Str::uuid(),
            'company_id'        => $this->companyA,
            'bank_account_id'   => $this->bank->id,
            'date_from'         => '2026-10-01',
            'statement_date'    => '2026-10-20',
            'opening_balance'   => '0.00',
            'statement_balance' => $statementBalance,
            'lines'             => collect($lines)->map(fn (string $status, int $id): array => [
                'journal_line_id' => $id, 'status' => $status,
            ])->values()->all(),
        ], $overrides);
    }

    /** @param array<int, string> $lines */
    private function createRecon(array $lines, string $statementBalance, array $overrides = []): BankReconciliation
    {
        $id = $this->actingAs($this->user)
            ->postJson('/api/v1/bank-reconciliations', $this->payload($lines, $statementBalance, $overrides))
            ->assertCreated()
            ->json('data.id');

        return BankReconciliation::findOrFail($id);
    }

    private function postRecon(BankReconciliation $recon): TestResponse
    {
        return $this->actingAs($this->user)->postJson("/api/v1/bank-reconciliations/{$recon->id}/post");
    }

    /** @return array<string, mixed> */
    private function workspace(string $date = '2026-10-20', ?int $reconId = null): array
    {
        return $this->actingAs($this->user)
            ->getJson('/api/v1/bank-reconciliations/workspace?bank_account_id=' . $this->bank->id
                . '&statement_date=' . $date . ($reconId ? '&reconciliation_id=' . $reconId : ''))
            ->assertOk()
            ->json('data');
    }

    // ── The workspace ────────────────────────────────────────────────────────

    public function test_the_workspace_lists_uncleared_bank_lines_up_to_the_statement_date(): void
    {
        $in  = $this->move(50000, true, '2026-10-02');
        $out = $this->move(12000, false, '2026-10-05', '100201');
        $this->move(999, false, '2026-10-22', '100202'); // after the statement date

        $data = $this->workspace();

        $this->assertTrue($data['is_first']);
        $this->assertNull($data['opening_balance']);
        $this->assertSame('38000.00', $data['book_balance']);

        $lines = collect($data['lines'])->keyBy('journal_line_id');
        $this->assertSame([$in, $out], $lines->keys()->all());
        $this->assertSame('deposit', $lines[$in]['direction']);
        $this->assertSame('50000.00', $lines[$in]['amount']);
        $this->assertSame('withdrawal', $lines[$out]['direction']);
        $this->assertSame('100201', $lines[$out]['instrument_no']);
        // A hand-posted line names no cheque document, so it cannot be returned.
        $this->assertNotNull($lines[$out]['return_blocked']);
    }

    public function test_a_voided_document_and_its_reversal_are_hidden_while_neither_has_cleared(): void
    {
        $kept = $this->move(5000, false, '2026-10-03', '100301');
        $this->move(7000, false, '2026-10-04', '100302');

        $entry = JournalEntry::latest('id')->firstOrFail();
        app(JournalPostingService::class)->reverse($entry, 'Wrong supplier', CarbonImmutable::parse('2026-10-06'));

        $ids = collect($this->workspace()['lines'])->pluck('journal_line_id')->all();

        $this->assertSame([$kept], $ids);
    }

    public function test_search_finds_uncleared_entries_after_the_statement_date_by_cheque_no(): void
    {
        $this->move(5000, false, '2026-10-03', '100301');
        $late = $this->move(800, false, '2026-10-24', '777001');

        $found = $this->actingAs($this->user)
            ->getJson('/api/v1/bank-reconciliations/search?bank_account_id=' . $this->bank->id . '&by=cheque&term=7770')
            ->assertOk()
            ->json('data');

        $this->assertSame([$late], array_column($found, 'journal_line_id'));
    }

    // ── Save and post ────────────────────────────────────────────────────────

    public function test_a_balanced_reconciliation_posts_and_clears_its_lines(): void
    {
        $in   = $this->move(50000, true, '2026-10-02');
        $out1 = $this->move(12000, false, '2026-10-05', '100201');
        $out2 = $this->move(3000, false, '2026-10-08', '100202');

        // The bank has the deposit and the first cheque; the second is unpresented.
        $recon = $this->createRecon([$in => 'realized', $out1 => 'realized', $out2 => 'not_presented'], '38000.00');

        $this->assertSame(BankReconciliationStatus::Draft, $recon->status);
        $this->assertSame('BR-2026-0001', $recon->recon_no);
        $this->assertSame('38000.00', (string) $recon->closing_balance);
        $this->assertSame('0.00', (string) $recon->difference);

        $this->postRecon($recon)
            ->assertOk()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.book_balance', '35000.00');

        // Next month: only the unpresented cheque is still open, and the
        // statement balance opens the next reconciliation.
        $next = $this->workspace('2026-10-25');
        $this->assertFalse($next['is_first']);
        $this->assertSame('38000.00', $next['opening_balance']);
        $this->assertSame([$out2], array_column($next['lines'], 'journal_line_id'));
    }

    public function test_posting_is_refused_until_the_variance_is_zero(): void
    {
        $in = $this->move(50000, true, '2026-10-02');

        $recon = $this->createRecon([$in => 'realized'], '49000.00');
        $this->assertSame('-1000.00', (string) $recon->difference);

        $this->postRecon($recon)->assertUnprocessable()->assertJsonValidationErrors('statement_balance');
        $this->assertSame(BankReconciliationStatus::Draft, $recon->fresh()->status);
    }

    public function test_the_first_reconciliation_takes_a_typed_opening_balance(): void
    {
        $in = $this->move(1000, true, '2026-10-02');

        $recon = $this->createRecon([$in => 'realized'], '26000.00', ['opening_balance' => '25000.00']);

        $this->assertSame('25000.00', (string) $recon->opening_balance);
        $this->postRecon($recon)->assertOk();
    }

    public function test_a_line_clears_only_once(): void
    {
        $in = $this->move(50000, true, '2026-10-02');
        $this->postRecon($this->createRecon([$in => 'realized'], '50000.00'))->assertOk();

        $this->actingAs($this->user)
            ->postJson('/api/v1/bank-reconciliations', $this->payload([$in => 'realized'], '100000.00', [
                'statement_date' => '2026-10-25',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.journal_line_id');
    }

    public function test_an_account_has_one_draft_and_statements_go_in_order(): void
    {
        $in = $this->move(50000, true, '2026-10-02');
        $draft = $this->createRecon([], '0.00');

        $this->actingAs($this->user)
            ->postJson('/api/v1/bank-reconciliations', $this->payload([], '0.00', ['statement_date' => '2026-10-21']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bank_account_id');

        $this->actingAs($this->user)->deleteJson("/api/v1/bank-reconciliations/{$draft->id}")->assertNoContent();
        $this->postRecon($this->createRecon([$in => 'realized'], '50000.00'))->assertOk();

        $this->actingAs($this->user)
            ->postJson('/api/v1/bank-reconciliations', $this->payload([], '50000.00', ['statement_date' => '2026-10-15']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('statement_date');
    }

    public function test_maker_checker_stops_the_author_posting(): void
    {
        $in = $this->move(50000, true, '2026-10-02');
        $recon = $this->createRecon([$in => 'realized'], '50000.00');

        app(FinanceControlService::class)->setMakerChecker(true);

        $this->postRecon($recon)->assertUnprocessable();

        $checker = $this->makeUser();
        $this->actingAs($checker)->postJson("/api/v1/bank-reconciliations/{$recon->id}/post")->assertOk();
    }

    // ── Undo and the void guard ──────────────────────────────────────────────

    public function test_a_reconciled_entry_cannot_be_reversed_until_the_reconciliation_is_undone(): void
    {
        $out   = $this->move(12000, false, '2026-10-05', '100201');
        $recon = $this->createRecon([$out => 'realized'], '-12000.00');
        $this->postRecon($recon)->assertOk();

        $entry = JournalLine::findOrFail($out)->entry;

        try {
            app(JournalPostingService::class)->reverse($entry, 'Void', CarbonImmutable::parse('2026-10-26'));
            $this->fail('Reversing a reconciled entry must be refused.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('reconciled in ' . $recon->recon_no, $e->getMessage());
        }

        $this->actingAs($this->user)
            ->postJson("/api/v1/bank-reconciliations/{$recon->id}/void", ['reason' => 'Matched the wrong cheque'])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided');

        app(JournalPostingService::class)->reverse($entry->fresh(), 'Void', CarbonImmutable::parse('2026-10-26'));
        $this->assertNotNull($entry->fresh()->reversed_by_journal_id);
    }

    public function test_only_the_latest_reconciliation_can_be_undone(): void
    {
        $a = $this->move(1000, true, '2026-10-02');
        $b = $this->move(2000, true, '2026-10-22');

        $first = $this->createRecon([$a => 'realized'], '1000.00');
        $this->postRecon($first)->assertOk();

        $second = $this->createRecon([$b => 'realized'], '3000.00', ['statement_date' => '2026-10-25', 'date_from' => '2026-10-21']);
        $this->postRecon($second)->assertOk();

        $this->actingAs($this->user)
            ->postJson("/api/v1/bank-reconciliations/{$first->id}/void", ['reason' => 'Undo'])
            ->assertUnprocessable();

        $this->actingAs($this->user)
            ->postJson("/api/v1/bank-reconciliations/{$second->id}/void", ['reason' => 'Undo'])
            ->assertOk();

        $this->assertSame([$b], array_column($this->workspace('2026-10-25')['lines'], 'journal_line_id'));
    }

    // ── Bank-only entries and returns ────────────────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function bankEntry(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations/bank-entries', array_merge([
            'bank_account_id' => $this->bank->id,
            'entry_date'      => '2026-10-18',
            'type'            => 'bank_charges',
            'amount'          => '350.00',
            'description'     => 'SMS alert charges',
            'reference'       => 'CHG-OCT',
        ], $overrides));
    }

    public function test_bank_charges_found_on_the_statement_post_and_can_be_realized(): void
    {
        $row = $this->bankEntry()->assertCreated()->json('data');

        $this->assertSame('withdrawal', $row['direction']);
        $this->assertSame('350.00', $row['amount']);
        $this->assertSame('CHG-OCT', $row['instrument_no']);

        // Cr the bank / Dr the mapped Bank Charges account -- never one the user picks.
        $line = JournalLine::findOrFail($row['journal_line_id']);
        $this->assertSame('350.0000', (string) $line->credit);
        $this->assertSame(350.0, (float) JournalLine::where('journal_entry_id', $line->journal_entry_id)
            ->where('ledger_account_id', $this->mapped(\Modules\Finance\Enums\AccountRole::BankCharges))->value('debit'));

        $this->postRecon($this->createRecon([$row['journal_line_id'] => 'realized'], '-350.00'))->assertOk();
    }

    public function test_a_bank_entry_is_only_charges_or_interest_and_posts_once_per_request(): void
    {
        // Any other account is out of reach: there is no field for it.
        $this->bankEntry(['type' => 'other', 'offset_account_id' => $this->income->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        // Interest goes into the bank, against the mapped Interest Income account.
        $interest = $this->bankEntry(['type' => 'interest', 'amount' => '120.50', 'description' => 'Interest for October'])
            ->assertCreated()->json('data');
        $this->assertSame('deposit', $interest['direction']);
        $entryId = JournalLine::findOrFail($interest['journal_line_id'])->journal_entry_id;
        $this->assertSame(120.5, (float) JournalLine::where('journal_entry_id', $entryId)
            ->where('ledger_account_id', $this->mapped(\Modules\Finance\Enums\AccountRole::InterestIncome))->value('credit'));

        // A double click sends the same request id: one journal, the same line back.
        $requestId = (string) Str::uuid();
        $first  = $this->bankEntry(['request_id' => $requestId])->assertCreated()->json('data.journal_line_id');
        $second = $this->bankEntry(['request_id' => $requestId])->assertCreated()->json('data.journal_line_id');

        $this->assertSame($first, $second);
        $this->assertSame(2, JournalEntry::where('narration', 'like', 'Bank statement entry:%')->count());
    }

    public function test_a_hand_posted_entry_cannot_be_returned(): void
    {
        // No document wrote it, so nothing can take it back (ChequeReturnTest covers the documents).
        $out = $this->move(12000, false, '2026-10-05', '100201');

        $this->actingAs($this->user)
            ->postJson('/api/v1/bank-reconciliations', $this->payload([$out => 'returned'], '0.00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.status');

        $this->assertSame(0, BankReconciliation::count());
    }

    // ── First reconciliation: start date and opening balance ─────────────────

    public function test_the_first_reconciliation_needs_a_start_date_and_an_opening_balance(): void
    {
        $in = $this->move(1000, true, '2026-10-02');

        $this->actingAs($this->user)
            ->postJson('/api/v1/bank-reconciliations', $this->payload([$in => 'realized'], '1000.00', ['date_from' => null, 'opening_balance' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_from', 'opening_balance']);

        // A typed 0.00 is a real answer.
        $this->createRecon([$in => 'realized'], '1000.00', ['opening_balance' => '0.00']);
    }

    public function test_entries_before_the_start_date_count_as_cleared_so_the_opening_journal_is_not_counted_twice(): void
    {
        // The opening balance journal, booked the day before go-live.
        $opening = $this->move(100000, true, '2026-09-30');
        $in      = $this->move(5000, true, '2026-10-03');

        $data = $this->actingAs($this->user)
            ->getJson('/api/v1/bank-reconciliations/workspace?bank_account_id=' . $this->bank->id . '&statement_date=2026-10-20&date_from=2026-10-01')
            ->assertOk()->json('data');

        $this->assertSame('2026-10-01', $data['cutoff_date']);
        $this->assertSame([$in], array_column($data['lines'], 'journal_line_id'));

        $recon = $this->createRecon([$in => 'realized'], '105000.00', ['opening_balance' => '100000.00']);
        $this->postRecon($recon)->assertOk();

        // It never comes back on a later statement either...
        $this->assertSame([], $this->workspace('2026-10-31')['lines']);

        // ...and the printed proof agrees with the books.
        $this->assertSame(0, app(\Modules\Finance\Services\BankReconciliationService::class)->openingGapCents($recon->fresh('lines')));

        // An old cheque from before go-live can still be found and realized when it turns up.
        $found = $this->actingAs($this->user)
            ->postJson('/api/v1/bank-reconciliations/search', ['bank_account_id' => $this->bank->id, 'by' => 'amount', 'term' => '100000'])
            ->assertOk()->json('data');
        $this->assertSame([$opening], array_column($found, 'journal_line_id'));
    }

    // ── Undo deletes the next draft ──────────────────────────────────────────

    public function test_undoing_a_reconciliation_deletes_the_draft_built_on_it(): void
    {
        $a = $this->move(1000, true, '2026-10-02');
        $b = $this->move(2000, true, '2026-10-22');

        $first = $this->createRecon([$a => 'realized'], '1000.00', ['opening_balance' => '0.00']);
        $this->postRecon($first)->assertOk();

        $draft = $this->createRecon([$b => 'realized'], '3000.00', ['statement_date' => '2026-10-25', 'date_from' => '2026-10-21']);
        $this->assertSame('1000.00', (string) $draft->opening_balance);

        $this->actingAs($this->user)
            ->postJson("/api/v1/bank-reconciliations/{$first->id}/void", ['reason' => 'Wrong statement'])
            ->assertOk();

        $this->assertSoftDeleted('fin_bank_reconciliations', ['id' => $draft->id]);

        // The chain starts again: both deposits are uncleared, and the next one is the first.
        $data = $this->workspace('2026-10-25');
        $this->assertTrue($data['is_first']);
        $this->assertSame([$a, $b], array_column($data['lines'], 'journal_line_id'));
    }

    // ── Bank period lock ─────────────────────────────────────────────────────

    public function test_nothing_can_be_back_dated_into_a_reconciled_period_of_the_bank(): void
    {
        $in    = $this->move(1000, true, '2026-10-02');
        $recon = $this->createRecon([$in => 'realized'], '1000.00');
        $this->postRecon($recon)->assertOk();

        try {
            $this->move(500, true, '2026-10-15');
            $this->fail('A posting dated inside the reconciled period must be refused.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('reconciled up to 20-10-2026 (' . $recon->recon_no . ')', $e->getMessage());
        }

        // After the statement date is fine, and so is any date once it is undone.
        $this->move(500, true, '2026-10-21');

        $this->actingAs($this->user)->postJson("/api/v1/bank-reconciliations/{$recon->id}/void", ['reason' => 'Redo'])->assertOk();
        $this->move(500, true, '2026-10-15');
    }

    // ── Maker-checker and voided pairs ───────────────────────────────────────

    public function test_maker_checker_also_stops_the_person_who_last_changed_the_matching(): void
    {
        $in    = $this->move(50000, true, '2026-10-02');
        $recon = $this->createRecon([$in => 'not_presented'], '50000.00');

        app(FinanceControlService::class)->setMakerChecker(true);

        $editor = $this->makeUser();
        $this->actingAs($editor)
            ->putJson("/api/v1/bank-reconciliations/{$recon->id}", $this->payload([$in => 'realized'], '50000.00', [
                'request_id' => null, 'updated_at' => $recon->fresh()->updated_at->toISOString(),
            ]))
            ->assertOk();

        $this->actingAs($editor)->postJson("/api/v1/bank-reconciliations/{$recon->id}/post")->assertUnprocessable();
        $this->actingAs($this->makeUser())->postJson("/api/v1/bank-reconciliations/{$recon->id}/post")->assertOk();
    }

    public function test_half_of_a_voided_pair_cannot_be_found_or_cleared(): void
    {
        $this->move(7000, false, '2026-10-04', '100302');
        $entry = JournalEntry::latest('id')->firstOrFail();
        app(JournalPostingService::class)->reverse($entry, 'Wrong supplier', CarbonImmutable::parse('2026-10-06'));

        $found = $this->actingAs($this->user)
            ->postJson('/api/v1/bank-reconciliations/search', ['bank_account_id' => $this->bank->id, 'by' => 'cheque', 'term' => '100302'])
            ->assertOk()->json('data');
        $this->assertSame([], $found);

        $mirror = (int) JournalLine::where('ledger_account_id', $this->bank->id)
            ->whereHas('entry', fn ($q) => $q->where('reverses_journal_id', $entry->id))
            ->value('id');

        $this->actingAs($this->user)
            ->postJson('/api/v1/bank-reconciliations', $this->payload([$mirror => 'realized'], '7000.00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines');
    }

    // ── The printed statement ────────────────────────────────────────────────

    public function test_the_reconciliation_statement_prints(): void
    {
        $in  = $this->move(50000, true, '2026-10-02');
        $out = $this->move(12000, false, '2026-10-05', '100201');

        $recon = $this->createRecon([$in => 'realized'], '50000.00');
        $this->postRecon($recon)->assertOk();

        // The unpresented cheque is what the statement lists between bank and books.
        $open = app(\Modules\Finance\Services\BankReconciliationService::class)->openItems($recon->fresh('lines'));
        $this->assertSame([$out], array_column($open['items'], 'journal_line_id'));
        $this->assertSame(-1200000, $open['net_cents']);
        $this->assertFalse($open['truncated']);

        $this->actingAs($this->user)
            ->get("/api/v1/bank-reconciliations/{$recon->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_an_entry_booked_after_the_statement_date_is_named_in_the_proof(): void
    {
        $in   = $this->move(1000, true, '2026-10-02');
        $late = $this->move(250, false, '2026-10-23', '100900');

        // The bank already paid the cheque the books dated three days later.
        $recon = $this->createRecon([$in => 'realized', $late => 'realized'], '750.00');
        $this->postRecon($recon)->assertOk();

        $service = app(\Modules\Finance\Services\BankReconciliationService::class);
        $recon->refresh()->load('lines');

        $this->assertSame(-25000, $service->bookedAfterCents($recon));

        // statement + open (net) - booked after + opening gap = book balance on the date.
        $open = $service->openItems($recon);
        $this->assertSame(
            $service->bookBalanceCents($this->bank->id, '2026-10-20'),
            75000 + $open['net_cents'] - $service->bookedAfterCents($recon) + $service->openingGapCents($recon),
        );
    }

    // ── Bank statement attachments ───────────────────────────────────────────

    private function attach(BankReconciliation $recon): TestResponse
    {
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('statement.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

        return $this->actingAs($this->user)
            ->post("/api/v1/finance-attachments/bank_reconciliation/{$recon->id}", ['file' => $file], ['Accept' => 'application/json']);
    }

    public function test_the_bank_statement_can_be_attached_and_removed_only_while_draft(): void
    {
        \Illuminate\Support\Facades\Storage::fake(\Modules\Finance\Services\AttachmentService::DISK);

        $in    = $this->move(1000, true, '2026-10-02');
        $recon = $this->createRecon([$in => 'realized'], '1000.00');

        $draftFile = $this->attach($recon)->assertCreated()->json('data.id');

        $this->actingAs($this->user)->getJson("/api/v1/finance-attachments/bank_reconciliation/{$recon->id}")
            ->assertOk()
            ->assertJsonPath('data.0.original_name', 'statement.pdf');

        $this->postRecon($recon)->assertOk();

        // Posted: a file can still be added (the statement often arrives later), but none removed.
        $this->attach($recon)->assertCreated();
        $this->actingAs($this->user)->deleteJson("/api/v1/finance-attachments/{$draftFile}")->assertUnprocessable();

        // Undone: the files stay for the audit trail, and nothing more is added.
        $this->actingAs($this->user)->postJson("/api/v1/bank-reconciliations/{$recon->id}/void", ['reason' => 'Undo'])->assertOk();
        $this->attach($recon)->assertUnprocessable();
        $this->assertCount(2, $this->actingAs($this->user)->getJson("/api/v1/finance-attachments/bank_reconciliation/{$recon->id}")->json('data'));
    }

    public function test_statement_files_need_the_reconciliation_permissions(): void
    {
        \Illuminate\Support\Facades\Storage::fake(\Modules\Finance\Services\AttachmentService::DISK);

        $recon = $this->createRecon([], '0.00');

        $this->actingAs($this->makeUser(['view_bills']))
            ->getJson("/api/v1/finance-attachments/bank_reconciliation/{$recon->id}")
            ->assertForbidden();

        $viewer = $this->makeUser(['view_bank_reconciliations']);
        $this->actingAs($viewer)->getJson("/api/v1/finance-attachments/bank_reconciliation/{$recon->id}")->assertOk();
        $this->actingAs($viewer)
            ->post("/api/v1/finance-attachments/bank_reconciliation/{$recon->id}", [
                'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('s.pdf', "%PDF-1.4\n%%EOF\n"),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_reconciliation_needs_its_permission(): void
    {
        $this->actingAs($this->makeUser(['view_bills']))
            ->getJson('/api/v1/bank-reconciliations')
            ->assertForbidden();
    }
}
