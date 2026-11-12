<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contracts\JournalPoster;
use App\Services\CompanyAccessService;
use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Finance\Enums\CashBookType;
use Modules\Finance\Models\BankReconciliation;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Models\JournalLine;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\JournalPostingService;
use Tests\Feature\Finance\Concerns\BillFixtures;
use Tests\TestCase;

/**
 * Uncleared Cheques report: cheques on a bank account the bank had not
 * cleared at the end of a chosen day, by the reconciliation's own rule.
 */
class UnclearedChequesReportTest extends TestCase
{
    use BillFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/finance-reports/uncleared-cheques';

    private LedgerAccount $bank;
    private LedgerAccount $income;

    protected function setUp(): void
    {
        parent::setUp();

        $this->billPermissions = [
            ...$this->billPermissions,
            'view_bank_reconciliations', 'create_bank_reconciliations', 'edit_bank_reconciliations',
            'post_bank_reconciliations', 'void_bank_reconciliations',
        ];

        $this->setUpBills();

        $this->bank   = $this->ledger('Sampath Current', 'Cash & Cash Equivalents', CashBookType::Bank, $this->companyA);
        $this->income = $this->ledger('Interest Received', 'Office & Establishment');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Post a money movement on a bank and return its bank journal line id. */
    private function move(float $amount, bool $in, string $date, ?string $chequeNo, ?LedgerAccount $bank = null, ?string $chequeDate = null): int
    {
        $bank ??= $this->bank;

        $bankLine = ($in
            ? JournalLineRequest::debit($bank->id, $amount, description: 'Money in')
            : JournalLineRequest::credit($bank->id, $amount, description: 'Money out'))
            ->withInstrument($chequeNo ? 'cheque' : 'bank_transfer', $chequeNo, $chequeNo ? ($chequeDate ?? $date) : null);

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
            ->where('ledger_account_id', $bank->id)
            ->value('id');
    }

    /** Post a reconciliation realizing the given lines. */
    private function reconcile(array $lineIds, string $statementDate, string $statementBalance): BankReconciliation
    {
        $id = $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'request_id'        => (string) Str::uuid(),
            'company_id'        => $this->companyA,
            'bank_account_id'   => $this->bank->id,
            'date_from'         => '2026-10-01',
            'statement_date'    => $statementDate,
            'opening_balance'   => '0.00',
            'statement_balance' => $statementBalance,
            'lines'             => array_map(fn (int $l): array => ['journal_line_id' => $l, 'status' => 'realized'], $lineIds),
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->user)->postJson("/api/v1/bank-reconciliations/{$id}/post")->assertOk();

        return BankReconciliation::findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function report(string $asAt, array $params = []): array
    {
        return $this->actingAs($this->user)
            ->getJson(self::URL . '?' . http_build_query(['as_at' => $asAt, ...$params]))
            ->assertOk()
            ->json('data');
    }

    // ── What is listed ───────────────────────────────────────────────────────

    public function test_it_lists_issued_and_deposited_cheques_not_yet_cleared(): void
    {
        $issued    = $this->move(12000, false, '2026-10-05', '100201');
        $deposited = $this->move(30000, true, '2026-10-06', '554411');
        $this->move(5000, true, '2026-10-07', null);              // a transfer: no cheque, not listed
        $this->move(800, false, '2026-10-25', '100202');          // after the As at date

        $data = $this->report('2026-10-20');
        $rows = collect($data['rows'])->keyBy('journal_line_id');

        $this->assertSame([$issued, $deposited], $rows->keys()->all());
        $this->assertSame('issued', $rows[$issued]['type']);
        $this->assertSame('100201', $rows[$issued]['cheque_no']);
        $this->assertSame(15, $rows[$issued]['days_outstanding']);
        $this->assertSame('received', $rows[$deposited]['type']);

        $this->assertSame(1, $data['summary']['issued_count']);
        $this->assertSame('12000.00', $data['summary']['issued_total']);
        $this->assertSame(1, $data['summary']['received_count']);
        $this->assertSame('30000.00', $data['summary']['received_total']);
        $this->assertCount(1, $data['banks']);
        $this->assertFalse($data['truncated']);
    }

    public function test_a_cheque_cleared_by_a_posted_reconciliation_drops_off_from_its_statement_date(): void
    {
        $this->move(900, false, '2026-09-28', '100100'); // before the account's start date: counts as cleared
        $cleared = $this->move(12000, false, '2026-10-05', '100201');
        $open    = $this->move(4000, false, '2026-10-06', '100203');

        $this->reconcile([$cleared], '2026-10-20', '-12000.00');

        // After the statement: only the cheque still outstanding.
        $this->assertSame([$open], array_column($this->report('2026-10-21')['rows'], 'journal_line_id'));

        // Before the statement it was still uncleared: an old date reprints as it stood.
        $this->assertSame([$cleared, $open], array_column($this->report('2026-10-19')['rows'], 'journal_line_id'));
    }

    public function test_undoing_the_reconciliation_puts_the_cheque_back(): void
    {
        $line  = $this->move(12000, false, '2026-10-05', '100201');
        $recon = $this->reconcile([$line], '2026-10-20', '-12000.00');

        $this->assertSame([], $this->report('2026-10-21')['rows']);

        $this->actingAs($this->user)
            ->postJson("/api/v1/bank-reconciliations/{$recon->id}/void", ['reason' => 'Wrong statement'])
            ->assertOk();

        $this->assertSame([$line], array_column($this->report('2026-10-21')['rows'], 'journal_line_id'));
    }

    public function test_a_voided_cheque_is_hidden_but_still_shows_on_dates_before_the_void(): void
    {
        $line  = $this->move(7000, false, '2026-10-04', '100302');
        $entry = JournalEntry::latest('id')->firstOrFail();

        app(JournalPostingService::class)->reverse($entry, 'Void', CarbonImmutable::parse('2026-10-10'));

        $this->assertSame([], $this->report('2026-10-20')['rows']);
        $this->assertSame([$line], array_column($this->report('2026-10-08')['rows'], 'journal_line_id'));
    }

    public function test_stale_and_post_dated_cheques_are_flagged(): void
    {
        $stale = $this->move(1000, false, '2026-03-01', '100001', chequeDate: '2026-03-01');
        $pdc   = $this->move(2000, true, '2026-10-01', '700001', chequeDate: '2026-11-30');

        $rows = collect($this->report('2026-10-20')['rows'])->keyBy('journal_line_id');

        $this->assertTrue($rows[$stale]['is_stale']);
        $this->assertFalse($rows[$stale]['is_post_dated']);
        $this->assertTrue($rows[$pdc]['is_post_dated']);
        $this->assertFalse($rows[$pdc]['is_stale']);
        $this->assertSame(1, $this->report('2026-10-20')['summary']['stale_count']);
    }

    // ── Filters ──────────────────────────────────────────────────────────────

    public function test_filters_by_type_bank_and_search(): void
    {
        $other     = $this->ledger('BOC Savings', 'Cash & Cash Equivalents', CashBookType::Bank, $this->companyA);
        $issued    = $this->move(12000, false, '2026-10-05', '100201');
        $deposited = $this->move(30000, true, '2026-10-06', '554411');
        $elsewhere = $this->move(900, false, '2026-10-06', '900900', $other);

        $this->assertSame([$issued, $elsewhere], array_column($this->report('2026-10-20', ['type' => 'issued'])['rows'], 'journal_line_id'));
        $this->assertSame([$deposited], array_column($this->report('2026-10-20', ['type' => 'received'])['rows'], 'journal_line_id'));
        $this->assertSame([$elsewhere], array_column($this->report('2026-10-20', ['bank_account_id' => $other->id])['rows'], 'journal_line_id'));
        $this->assertSame([$deposited], array_column($this->report('2026-10-20', ['search' => '5544'])['rows'], 'journal_line_id'));

        $this->assertCount(2, $this->report('2026-10-20')['banks']);
    }

    public function test_another_companys_bank_account_is_never_listed(): void
    {
        $theirs = $this->ledger('Beta Bank', 'Cash & Cash Equivalents', CashBookType::Bank, $this->companyB);
        $this->move(500, false, '2026-10-05', '300300', $theirs);
        $mine = $this->move(600, false, '2026-10-05', '300301');

        $user = $this->makeUser(['view_bank_reconciliations']);
        app(CompanyAccessService::class)->sync($user, [$this->companyA]);

        $rows = $this->actingAs($user)->getJson(self::URL . '?as_at=2026-10-20')->assertOk()->json('data.rows');
        $this->assertSame([$mine], array_column($rows, 'journal_line_id'));

        // Asking for it directly gives nothing either.
        $rows = $this->actingAs($user)->getJson(self::URL . '?as_at=2026-10-20&bank_account_id=' . $theirs->id)->assertOk()->json('data.rows');
        $this->assertSame([], $rows);

        $banks = $this->actingAs($user)->getJson(self::URL . '/options')->assertOk()->json('data.bank_accounts');
        $this->assertNotContains($theirs->id, array_column($banks, 'id'));
    }

    // ── Validation, permission, exports ──────────────────────────────────────

    public function test_the_as_at_date_is_required_and_not_in_the_future(): void
    {
        $this->actingAs($this->user)->getJson(self::URL)->assertUnprocessable()->assertJsonValidationErrors('as_at');

        $this->actingAs($this->user)
            ->getJson(self::URL . '?as_at=' . now()->addDay()->toDateString())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('as_at');

        $this->actingAs($this->user)
            ->getJson(self::URL . '?as_at=2026-10-20&type=bogus')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_it_needs_a_banking_or_finance_report_permission(): void
    {
        \Spatie\Permission\Models\Permission::findOrCreate('view_finance_reports', 'web');

        $this->actingAs($this->makeUser(['view_bills']))->getJson(self::URL . '?as_at=2026-10-20')->assertForbidden();
        $this->actingAs($this->makeUser(['view_finance_reports']))->getJson(self::URL . '?as_at=2026-10-20')->assertOk();
    }

    public function test_it_downloads_as_pdf_and_csv(): void
    {
        $this->move(12000, false, '2026-10-05', '100201');
        $this->move(30000, true, '2026-10-06', '=HYPERLINK("x")');

        $this->actingAs($this->user)
            ->get(self::URL . '/pdf?as_at=2026-10-20')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $csv = $this->actingAs($this->user)->get(self::URL . '/csv?as_at=2026-10-20')->assertOk()->streamedContent();

        $this->assertStringContainsString('100201', $csv);
        $this->assertStringContainsString('12000.00', $csv);
        // A cell that looks like a formula is neutralised for Excel.
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }
}
