<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Database\Seeders\FinanceChartOfAccountsSeeder;
use Modules\Finance\Database\Seeders\FinanceGlAccountsSeeder;
use Modules\Finance\Models\LedgerAccount;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * QA pass over the Trial Balance, the account drill-down and Ledger Details:
 * amounts on awkward data, which journals count, filters, cross-report
 * agreement, query counts and company access.
 *
 * Journals are inserted directly so the edge cases (4-decimal amounts,
 * drafts, deleted, reversed, mixed companies) can be set up exactly.
 */
class LedgerReportsQaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        app(SettingsService::class)->set('module.finance', true);

        Permission::create(['name' => 'view_finance_reports', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['active_modules' => ['finance']]);
        $this->user->givePermissionTo('view_finance_reports');

        $this->seed(FinanceChartOfAccountsSeeder::class);
        $this->seed(FinanceGlAccountsSeeder::class);
    }

    // ── Amounts ──────────────────────────────────────────────────────────────

    public function test_drafts_and_deleted_journals_never_count_but_reversals_net_to_zero(): void
    {
        $p = $this->acc('Purchases');
        $ap = $this->acc('Accounts Payable');

        $this->journal('2026-04-05', [[$p, 100, 0], [$ap, 0, 100]]);                       // counts
        $this->journal('2026-04-06', [[$p, 999, 0], [$ap, 0, 999]], 'draft');              // never
        $this->journal('2026-04-07', [[$p, 777, 0], [$ap, 0, 777]], 'posted', true);       // deleted
        $orig = $this->journal('2026-04-08', [[$p, 50, 0], [$ap, 0, 50]], 'reversed');
        $this->journal('2026-04-09', [[$p, 0, 50], [$ap, 50, 0]], 'posted', false, $orig);  // reversal

        $tb  = collect($this->fetchOk('/api/v1/finance-reports/trial-balance')->json('data.rows'))->firstWhere('ledger_account_id', $p);
        $ld  = $this->ledgerRow($p);
        $dd  = $this->fetchOk("/api/v1/finance-reports/trial-balance/accounts/{$p}/transactions");

        $this->assertMoney(100.0, $tb['debit_balance']);
        $this->assertMoney(150.0, $tb['debit_total']);   // gross includes the reversed pair
        $this->assertMoney(100.0, $ld['closing']);
        $this->assertSame(3, $ld['line_count']);
        $this->assertMoney(100.0, $dd->json('data.summary.closing'));
        $dd->assertJsonCount(3, 'data.rows');
    }

    public function test_four_decimal_amounts_keep_the_running_balance_equal_to_the_closing(): void
    {
        $p  = $this->acc('Purchases');
        $ap = $this->acc('Accounts Payable');

        // Automatic postings store 4 decimals. Three lines of 0.3333 + 0.0001 residue.
        for ($i = 1; $i <= 3; $i++) {
            $this->journal("2026-04-0{$i}", [[$p, 0.3333, 0], [$ap, 0, 0.3333]]);
        }
        $this->journal('2026-04-04', [[$p, 0.0001, 0], [$ap, 0, 0.0001]]);

        $dd   = $this->fetchOk("/api/v1/finance-reports/trial-balance/accounts/{$p}/transactions");
        $rows = $dd->json('data.rows');

        $this->assertMoney(1.0, $dd->json('data.summary.closing'), 'summary closing');
        $this->assertMoney(
            (float) $dd->json('data.summary.closing'),
            (float) end($rows)['balance'],
            'The last running balance must equal the closing balance printed under it.',
        );
    }

    public function test_four_decimal_amounts_carry_the_same_balance_across_pages(): void
    {
        $p  = $this->acc('Purchases');
        $ap = $this->acc('Accounts Payable');

        for ($i = 0; $i < 51; $i++) {
            $this->journal('2026-04-' . str_pad((string) (1 + intdiv($i, 3)), 2, '0', STR_PAD_LEFT), [[$p, 10.0049, 0], [$ap, 0, 10.0049]]);
        }

        $page1 = $this->fetchOk("/api/v1/finance-reports/trial-balance/accounts/{$p}/transactions?page=1");
        $page2 = $this->fetchOk("/api/v1/finance-reports/trial-balance/accounts/{$p}/transactions?page=2");

        $rows1 = $page1->json('data.rows');
        $this->assertMoney((float) end($rows1)['balance'], (float) $page1->json('data.meta.balance_cf'), 'page 1 c/f = its last row');
        $this->assertMoney((float) $page1->json('data.meta.balance_cf'), (float) $page2->json('data.meta.balance_bf'), 'page 2 b/f = page 1 c/f');
        $this->assertMoney((float) $page2->json('data.summary.closing'), (float) $page2->json('data.meta.balance_cf'), 'last page c/f = closing');
    }

    public function test_ledger_details_grand_totals_equal_the_detailed_trial_balance(): void
    {
        $accounts = LedgerAccount::query()->orderBy('code')->limit(12)->pluck('id')->all();

        // A spread of journals before and inside the period across 12 accounts.
        foreach ($accounts as $i => $id) {
            $other = $accounts[($i + 5) % count($accounts)];
            $this->journal('2026-03-1' . ($i % 9), [[$id, 100 + $i, 0], [$other, 0, 100 + $i]]);
            $this->journal('2026-04-1' . ($i % 9), [[$other, 33.33 * ($i + 1), 0], [$id, 0, 33.33 * ($i + 1)]]);
        }

        $q   = 'date_from=2026-04-01&date_to=2026-04-30';
        $tb  = $this->fetchOk("/api/v1/finance-reports/trial-balance?{$q}&detailed=1")->json('data.detail_totals');
        $ld  = $this->fetchOk("/api/v1/finance-reports/ledger-details?{$q}")->json('data.totals');

        foreach (['opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'closing_debit', 'closing_credit'] as $k) {
            $this->assertMoney((float) $tb[$k], (float) $ld[$k], $k);
        }
        $this->assertMoney((float) $ld['closing_debit'], (float) $ld['closing_credit'], 'closing balances agree');
    }

    public function test_location_and_company_filter_on_the_line_not_the_header(): void
    {
        $p  = $this->acc('Purchases');
        $ap = $this->acc('Accounts Payable');

        $this->journal('2026-04-05', [[$p, 100, 0, 1, 10], [$ap, 0, 100, 1, 10]]);
        $this->journal('2026-04-06', [[$p, 40, 0, 2, 20], [$ap, 0, 40, 2, 20]]);

        $this->assertMoney(100.0, $this->ledgerRow($p, 'location_id=10')['closing']);
        $this->assertMoney(40.0, $this->ledgerRow($p, 'company_id=2')['closing']);
        $this->assertMoney(40.0, $this->fetchOk("/api/v1/finance-reports/trial-balance/accounts/{$p}/transactions?location_id=20")->json('data.summary.closing'));
    }

    public function test_an_archived_account_with_history_still_reports(): void
    {
        $p  = $this->acc('Purchases');
        $ap = $this->acc('Accounts Payable');
        $this->journal('2026-04-05', [[$p, 100, 0], [$ap, 0, 100]]);

        DB::table('fin_ledger_accounts')->where('id', $p)->update(['deleted_at' => now(), 'is_active' => false]);

        $row = $this->ledgerRow($p);
        $this->assertMoney(100.0, $row['closing']);
        $this->assertFalse($row['is_active']);
        $this->fetchOk("/api/v1/finance-reports/trial-balance/accounts/{$p}/transactions")->assertOk();
    }

    public function test_accounts_page_25_at_a_time_and_a_page_past_the_end_clamps(): void
    {
        // The seeded chart is small: add accounts until it spans two pages.
        $template = LedgerAccount::query()->firstOrFail();
        for ($i = 1; $i <= 30; $i++) {
            DB::table('fin_ledger_accounts')->insert([
                'code' => '9QA' . str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'ledger_account_name' => "QA {$i}",
                'control_account_id' => $template->control_account_id, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $r = $this->fetchOk('/api/v1/finance-reports/ledger-details?include_no_activity=1');
        $total = (int) $r->json('data.meta.total');
        $this->assertGreaterThan(25, $total);
        $r->assertJsonCount(25, 'data.accounts');

        $last = $this->fetchOk('/api/v1/finance-reports/ledger-details?include_no_activity=1&page=999');
        $last->assertJsonPath('data.meta.current_page', (int) ceil($total / 25));
    }

    // ── Queries ──────────────────────────────────────────────────────────────

    public function test_query_counts_do_not_grow_with_the_number_of_accounts(): void
    {
        $accounts = LedgerAccount::query()->orderBy('code')->pluck('id')->all();
        $n        = count($accounts);
        foreach ($accounts as $i => $id) {
            $this->journal('2026-04-10', [[$id, 10, 0], [$accounts[($i + 1) % $n], 0, 10]]);
        }

        $few  = $this->countQueries(fn () => $this->fetchOk('/api/v1/finance-reports/ledger-details?account_ids[]=' . $accounts[0]));
        $many = $this->countQueries(fn () => $this->fetchOk('/api/v1/finance-reports/ledger-details'));
        $this->assertSame($few, $many, "report queries: {$few} for one account vs {$many} for all");

        $tb = $this->countQueries(fn () => $this->fetchOk('/api/v1/finance-reports/trial-balance?detailed=1'));
        $dd = $this->countQueries(fn () => $this->fetchOk("/api/v1/finance-reports/trial-balance/accounts/{$accounts[0]}/transactions"));

        $pdf = fn (string $q) => $this->countQueries(fn () => $this->actingAs($this->user)->get('/api/v1/finance-reports/ledger-details/pdf?' . $q)->assertOk());
        $pdfList = $pdf('view=list');
        $pdfT    = $pdf('view=t_account&location_id=1');

        fwrite(STDERR, sprintf(
            "\n[QA] queries: ledger-details=%d, trial-balance=%d, drill-down page=%d, pdf list (%d accounts)=%d, pdf t-account+location=%d\n",
            $many, $tb, $dd, $n, $pdfList, $pdfT,
        ));
        $this->assertLessThan(20, $dd);
        $this->assertLessThanOrEqual(12, $pdfList, 'the PDF must not query per account');
    }

    public function test_the_batched_print_carries_exactly_the_same_details_as_one_account(): void
    {
        $p  = $this->acc('Purchases');
        $ap = $this->acc('Accounts Payable');
        $cash = (int) LedgerAccount::query()->whereNotIn('id', [$p, $ap])->orderBy('code')->value('id');

        $this->journal('2026-03-20', [[$p, 300, 0], [$ap, 0, 300]]);
        $this->journal('2026-04-05', [[$p, 100, 0], [$ap, 0, 60], [$cash, 0, 40]]);   // a split
        $this->journal('2026-04-09', [[$ap, 25, 0], [$cash, 0, 25]]);

        $service  = app(\Modules\Finance\Services\AccountTransactionsService::class);
        $filters  = ['date_from' => '2026-04-01', 'date_to' => '2026-04-30'];
        $accounts = LedgerAccount::query()->whereIn('id', [$p, $ap, $cash])->orderBy('code')->get();

        $batched = collect($service->statements($accounts, $filters))->keyBy('account.id');

        foreach ($accounts as $account) {
            $this->assertEquals(
                $service->statement($account, $filters),
                $batched[$account->id],
                "{$account->ledger_account_name}: batched statement differs from the single-account one",
            );
        }

        // The shared budget stops lines but never balances.
        $tight = $service->statements($accounts, $filters, 2);
        $this->assertTrue(collect($tight)->contains('truncated', true));
        foreach ($tight as $s) {
            $this->assertEquals($batched[$s['account']['id']]['summary'], $s['summary']);
        }
    }

    // ── Security ─────────────────────────────────────────────────────────────

    public function test_a_company_restricted_user_does_not_see_other_companies_lines(): void
    {
        $a = (int) DB::table('inv_companies')->insertGetId(['company_name' => 'Alpha', 'created_at' => now(), 'updated_at' => now()]);
        $b = (int) DB::table('inv_companies')->insertGetId(['company_name' => 'Beta', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_company_access')->insert(['user_id' => $this->user->id, 'company_id' => $a, 'created_at' => now(), 'updated_at' => now()]);

        $p  = $this->acc('Purchases');
        $ap = $this->acc('Accounts Payable');
        $this->journal('2026-04-05', [[$p, 100, 0, $a], [$ap, 0, 100, $a]]);
        $this->journal('2026-04-06', [[$p, 5000, 0, $b], [$ap, 0, 5000, $b]]);
        $this->journal('2026-04-07', [[$p, 7, 0], [$ap, 0, 7]]);   // group-wide: no company

        // Alpha + group-wide only, in all three reports.
        $tb = collect($this->fetchOk('/api/v1/finance-reports/trial-balance')->json('data.rows'))->firstWhere('ledger_account_id', $p);
        $this->assertMoney(107.0, $tb['debit_balance'], 'Trial Balance must not show Beta\'s 5,000 to an Alpha-only user');
        $this->assertMoney(107.0, $this->ledgerRow($p)['closing'], 'Ledger Details must not show Beta to an Alpha-only user');
        $this->assertMoney(107.0, $this->fetchOk("/api/v1/finance-reports/trial-balance/accounts/{$p}/transactions")->json('data.summary.closing'));
        $this->assertTrue($this->fetchOk('/api/v1/finance-reports/trial-balance')->json('data.totals.balanced'), 'restricted TB still balances');

        // Asking for Beta outright is refused on every endpoint.
        foreach ([
            "/api/v1/finance-reports/trial-balance?company_id={$b}",
            "/api/v1/finance-reports/trial-balance/accounts/{$p}/transactions?company_id={$b}",
            "/api/v1/finance-reports/ledger-details?company_id={$b}",
            "/api/v1/finance-reports/ledger-details/pdf?company_id={$b}",
            "/api/v1/finance-reports/trial-balance/accounts/{$p}/t-account/pdf?company_id={$b}",
        ] as $url) {
            $this->actingAs($this->user)->getJson($url)->assertForbidden();
        }
        $this->fetchOk("/api/v1/finance-reports/ledger-details?company_id={$a}");

        // Beta's own bank account: not offered, not selectable, not openable.
        DB::table('fin_ledger_accounts')->where('id', $ap)->update(['company_id' => $b]);
        $this->assertNull(collect($this->fetchOk('/api/v1/finance-reports/ledger-details/options')->json('data.accounts'))->firstWhere('id', $ap));
        $this->assertEmpty($this->fetchOk("/api/v1/finance-reports/ledger-details?account_ids[]={$ap}")->json('data.accounts'));
        $this->actingAs($this->user)->getJson("/api/v1/finance-reports/trial-balance/accounts/{$ap}/transactions")->assertForbidden();
    }

    public function test_super_admin_and_unassigned_users_see_every_company(): void
    {
        $b = (int) DB::table('inv_companies')->insertGetId(['company_name' => 'Beta', 'created_at' => now(), 'updated_at' => now()]);
        $p  = $this->acc('Purchases');
        $ap = $this->acc('Accounts Payable');
        $this->journal('2026-04-06', [[$p, 5000, 0, $b], [$ap, 0, 5000, $b]]);

        // No rows in user_company_access = every company.
        $this->assertMoney(5000.0, $this->ledgerRow($p)['closing']);

        // super_admin is never restricted, even with an assignment.
        \Spatie\Permission\Models\Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $this->user->assignRole('super_admin');
        DB::table('user_company_access')->insert(['user_id' => $this->user->id, 'company_id' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertMoney(5000.0, $this->ledgerRow($p, "company_id={$b}")['closing']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param list<array{0:int,1:float,2:float,3?:?int,4?:?int}> $lines account, debit, credit, company, location
     */
    private function journal(string $date, array $lines, string $status = 'posted', bool $deleted = false, ?int $reverses = null): int
    {
        $this->seq++;

        $id = (int) DB::table('fin_journal_entries')->insertGetId([
            'journal_no'          => 'QA-' . str_pad((string) $this->seq, 5, '0', STR_PAD_LEFT),
            'journal_date'        => $date,
            'journal_type'        => 'general',
            'status'              => $status,
            'narration'           => 'QA ' . $this->seq,
            'reverses_journal_id' => $reverses,
            'deleted_at'          => $deleted ? now() : null,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        foreach ($lines as $n => $l) {
            DB::table('fin_journal_lines')->insert([
                'journal_entry_id'  => $id,
                'ledger_account_id' => $l[0],
                'line_no'           => $n + 1,
                'debit'             => $l[1],
                'credit'            => $l[2],
                'company_id'        => $l[3] ?? null,
                'location_id'       => $l[4] ?? null,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }

        return $id;
    }

    private function fetchOk(string $url): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->getJson($url)->assertOk();
    }

    /** @return array<string, mixed> */
    private function ledgerRow(int $accountId, string $query = ''): array
    {
        $rows = $this->fetchOk("/api/v1/finance-reports/ledger-details?account_ids[]={$accountId}" . ($query ? "&{$query}" : ''))->json('data.accounts');
        $this->assertCount(1, $rows);

        return $rows[0];
    }

    private function acc(string $name): int
    {
        return (int) LedgerAccount::withTrashed()->where('ledger_account_name', $name)->value('id');
    }

    private function countQueries(callable $fn): int
    {
        $this->actingAs($this->user)->getJson('/api/v1/finance-reports/ledger-details'); // warm auth, permission and settings caches
        $count = 0;
        DB::listen(function () use (&$count): void { $count++; });
        $fn();
        $n = $count;
        $count = PHP_INT_MIN; // stop counting for later listeners' calls

        return $n;
    }

    private function assertMoney(float $expected, mixed $actual, string $message = ''): void
    {
        $this->assertEqualsWithDelta($expected, (float) $actual, 0.0001, $message);
    }
}
