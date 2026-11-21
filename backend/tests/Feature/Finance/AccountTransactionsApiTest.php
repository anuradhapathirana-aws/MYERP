<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Database\Seeders\FinanceChartOfAccountsSeeder;
use Modules\Finance\Database\Seeders\FinanceGlAccountsSeeder;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\AccountTransactionsService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Trial Balance drill-down: one account's transactions with opening,
 * running and closing balances, over HTTP.
 */
class AccountTransactionsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        app(SettingsService::class)->set('module.finance', true);

        $permissions = ['view_journal_entries', 'create_journal_entries', 'view_finance_reports'];

        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['finance']]);
        $this->user->givePermissionTo($permissions);

        $this->seed(FinanceChartOfAccountsSeeder::class);
        $this->seed(FinanceGlAccountsSeeder::class);
    }

    public function test_it_lists_transactions_with_a_running_balance_and_contra_account(): void
    {
        $this->post2('2026-04-10', 1000);
        $this->post2('2026-04-20', 500);

        $response = $this->fetch($this->account('Purchases'))->assertOk();

        $response->assertJsonPath('data.account.ledger_account_name', 'Purchases')
            ->assertJsonPath('data.account.normal_balance', 'debit')
            ->assertJsonCount(2, 'data.rows')
            ->assertJsonPath('data.rows.0.source_label', 'Journal Voucher')
            ->assertJsonPath('data.rows.0.contra.label', 'Accounts Payable')
            ->assertJsonPath('data.summary.is_filtered', false);

        $this->assertMoney(1000.0, $response->json('data.rows.0.balance'));
        $this->assertMoney(1500.0, $response->json('data.rows.1.balance'));
        $this->assertMoney(0.0, $response->json('data.summary.opening'));
        $this->assertMoney(1500.0, $response->json('data.summary.closing'));

        // A credit account runs negative (credit side).
        $payables = $this->fetch($this->account('Accounts Payable'))->assertOk();
        $this->assertMoney(-1500.0, $payables->json('data.rows.1.balance'));
        $payables->assertJsonPath('data.rows.0.contra.label', 'Purchases');
    }

    public function test_a_from_date_brings_the_earlier_lines_in_as_opening_balance(): void
    {
        $this->post2('2026-03-15', 1000);
        $this->post2('2026-04-20', 500);

        $response = $this->fetch($this->account('Purchases'), ['date_from' => '2026-04-01'])->assertOk();

        $response->assertJsonCount(1, 'data.rows');
        $this->assertMoney(1000.0, $response->json('data.summary.opening'));
        $this->assertMoney(500.0, $response->json('data.summary.period_net'));
        $this->assertMoney(1500.0, $response->json('data.summary.closing'));
        $this->assertMoney(1500.0, $response->json('data.rows.0.balance'));

        // Period movement equals the Trial Balance row for the same range.
        $tb = $this->actingAs($this->user)
            ->getJson('/api/v1/finance-reports/trial-balance?date_from=2026-04-01')
            ->json('data.rows');
        $this->assertMoney(500.0, collect($tb)->firstWhere('ledger_account_name', 'Purchases')['debit_balance']);
    }

    public function test_the_running_balance_carries_across_pages(): void
    {
        for ($i = 1; $i <= 51; $i++) {
            $this->post2('2026-04-' . str_pad((string) (1 + intdiv($i, 3)), 2, '0', STR_PAD_LEFT), 10);
        }

        $page2 = $this->fetch($this->account('Purchases'), ['page' => 2])->assertOk();

        $page2->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.meta.total', 51)
            ->assertJsonPath('data.meta.last_page', 2);

        $this->assertMoney(510.0, $page2->json('data.rows.0.balance'));

        // Page 1 carries 500 forward; page 2 brings that same 500 forward.
        $page1 = $this->fetch($this->account('Purchases'), ['page' => 1])->assertOk();
        $this->assertMoney(0.0, $page1->json('data.meta.balance_bf'));
        $this->assertMoney(500.0, $page1->json('data.meta.balance_cf'));
        $this->assertMoney(500.0, $page2->json('data.meta.balance_bf'));
        $this->assertMoney(510.0, $page2->json('data.meta.balance_cf'));
    }

    public function test_the_detailed_trial_balance_carries_each_account_forward(): void
    {
        $this->post2('2026-03-15', 1000);   // before the period: b/f only
        $this->post2('2026-04-20', 500);    // in the period

        // An account with only a b/f balance and no period movement.
        $this->actingAs($this->user)->postJson('/api/v1/journal-entries', [
            'journal_date' => '2026-03-20',
            'journal_type' => 'general',
            'narration'    => 'March sale',
            'lines'        => [
                ['ledger_account_id' => $this->account('Accounts Receivable'), 'debit' => 300, 'credit' => 0],
                ['ledger_account_id' => $this->account('Sales Revenue'), 'debit' => 0, 'credit' => 300],
            ],
        ])->assertCreated();

        $url = '/api/v1/finance-reports/trial-balance?date_from=2026-04-01';

        // Standard view: unchanged, movement-only.
        $standard = $this->actingAs($this->user)->getJson($url)->assertOk();
        $this->assertCount(2, $standard->json('data.rows'));

        // Detailed view: every account with a b/f balance appears.
        $detailed = $this->actingAs($this->user)->getJson($url . '&detailed=1')->assertOk();
        $rows     = collect($detailed->json('data.rows'));

        $this->assertCount(4, $rows);

        $purchases = $rows->firstWhere('ledger_account_name', 'Purchases');
        $this->assertMoney(1000.0, $purchases['opening_debit']);
        $this->assertMoney(500.0, $purchases['debit_total']);
        $this->assertMoney(1500.0, $purchases['closing_debit']);
        $this->assertMoney(500.0, $purchases['debit_balance'], 'Standard column stays period-only.');

        $receivable = $rows->firstWhere('ledger_account_name', 'Accounts Receivable');
        $this->assertFalse($receivable['has_movement']);
        $this->assertMoney(300.0, $receivable['opening_debit']);
        $this->assertMoney(300.0, $receivable['closing_debit']);

        $detailed->assertJsonPath('data.detail_totals.balanced', true)
            ->assertJsonPath('data.totals.balanced', true);

        $this->assertMoney(1300.0, $detailed->json('data.detail_totals.opening_debit'));
        $this->assertMoney(1300.0, $detailed->json('data.detail_totals.opening_credit'));
        $this->assertMoney(1800.0, $detailed->json('data.detail_totals.closing_debit'));
        $this->assertMoney(500.0, $detailed->json('data.totals.debit'));
    }

    public function test_a_search_hides_the_running_balance_but_keeps_the_summary(): void
    {
        $this->post2('2026-04-10', 1000, 'Rent April');
        $this->post2('2026-04-20', 500, 'Stationery');

        $response = $this->fetch($this->account('Purchases'), ['search' => 'Rent'])->assertOk();

        $response->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.balance', null)
            ->assertJsonPath('data.summary.is_filtered', true);

        $this->assertMoney(1500.0, $response->json('data.summary.closing'));
    }

    public function test_a_journal_with_several_opposite_accounts_reads_as_split(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/journal-entries', [
            'journal_date' => '2026-04-10',
            'journal_type' => 'general',
            'narration'    => 'Split purchase',
            'lines'        => [
                ['ledger_account_id' => $this->account('Purchases'), 'debit' => 300, 'credit' => 0],
                ['ledger_account_id' => $this->account('Accounts Payable'), 'debit' => 0, 'credit' => 200],
                ['ledger_account_id' => $this->account('Sales Revenue'), 'debit' => 0, 'credit' => 100],
            ],
        ])->assertCreated();

        $response = $this->fetch($this->account('Purchases'))->assertOk();

        $response->assertJsonPath('data.rows.0.contra.label', 'Split (2 accounts)')
            ->assertJsonCount(2, 'data.rows.0.contra.accounts');
    }

    public function test_it_needs_the_finance_reports_permission(): void
    {
        $other = User::factory()->create(['active_modules' => ['finance']]);

        $this->actingAs($other)
            ->getJson('/api/v1/finance-reports/trial-balance/accounts/' . $this->account('Purchases') . '/transactions')
            ->assertForbidden();
    }

    public function test_a_to_date_before_the_from_date_is_refused(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/finance-reports/trial-balance/accounts/' . $this->account('Purchases')
                . '/transactions?date_from=2026-05-01&date_to=2026-04-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_to');
    }

    // ── T-account ────────────────────────────────────────────────────────────

    public function test_the_t_account_balances_with_b_d_and_c_d(): void
    {
        $this->post2('2026-03-15', 10000);   // b/d
        $this->post2('2026-04-05', 2500);
        // A refund: Dr Accounts Payable / Cr Purchases.
        $this->actingAs($this->user)->postJson('/api/v1/journal-entries', [
            'journal_date' => '2026-04-20',
            'journal_type' => 'general',
            'narration'    => 'Refund',
            'lines'        => [
                ['ledger_account_id' => $this->account('Accounts Payable'), 'debit' => 1000, 'credit' => 0],
                ['ledger_account_id' => $this->account('Purchases'), 'debit' => 0, 'credit' => 1000],
            ],
        ])->assertCreated();

        $this->actingAs($this->user);
        $t = app(AccountTransactionsService::class)->tAccount(
            LedgerAccount::find($this->account('Purchases')),
            ['date_from' => '2026-04-01', 'date_to' => '2026-04-30'],
        );

        $this->assertSame('To Balance b/d', $t['debit'][0]['particulars']);
        $this->assertMoney(10000.0, $t['debit'][0]['amount']);
        $this->assertSame('To Accounts Payable', $t['debit'][1]['particulars']);
        $this->assertSame('By Accounts Payable', $t['credit'][0]['particulars']);
        $this->assertSame('By Balance c/d', $t['credit'][1]['particulars']);
        $this->assertMoney(11500.0, $t['credit'][1]['amount']);

        // Both sides total the same, and the closing reopens on the debit side.
        $this->assertMoney(12500.0, $t['total']);
        $this->assertSame('debit', $t['reopen']['side']);
        $this->assertSame('2026-05-01', $t['reopen']['date']);
        $this->assertMoney(11500.0, $t['reopen']['amount']);
    }

    public function test_the_t_account_pdf_downloads(): void
    {
        $this->post2('2026-04-10', 1000);

        $response = $this->actingAs($this->user)
            ->get('/api/v1/finance-reports/trial-balance/accounts/' . $this->account('Purchases') . '/t-account/pdf?date_from=2026-04-01')
            ->assertOk();

        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    public function test_the_default_ledger_view_is_a_super_admin_setting(): void
    {
        $this->post2('2026-04-10', 1000);

        // Out of the box: list.
        $this->fetch($this->account('Purchases'))->assertJsonPath('data.default_view', 'list');

        Permission::findOrCreate('view_finance_settings', 'web');
        $this->user->givePermissionTo('view_finance_settings');

        // An ordinary finance user cannot change it.
        $this->actingAs($this->user)
            ->putJson('/api/v1/finance-settings/ledger-view', ['ledger_view' => 't_account'])
            ->assertForbidden();

        Role::findOrCreate('super_admin', 'web');
        $it = User::factory()->create(['role' => UserRole::SuperAdmin, 'active_modules' => ['finance']]);
        $it->assignRole('super_admin');

        $this->actingAs($it)
            ->putJson('/api/v1/finance-settings/ledger-view', ['ledger_view' => 'nonsense'])
            ->assertStatus(422);

        $this->actingAs($it)
            ->putJson('/api/v1/finance-settings/ledger-view', ['ledger_view' => 't_account'])
            ->assertOk()
            ->assertJsonPath('data.ledger_view', 't_account');

        $this->fetch($this->account('Purchases'))->assertJsonPath('data.default_view', 't_account');
        $this->actingAs($this->user)->getJson('/api/v1/finance-settings')->assertJsonPath('data.ledger_view', 't_account');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function fetch(int $accountId, array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->getJson(
            '/api/v1/finance-reports/trial-balance/accounts/' . $accountId . '/transactions?' . http_build_query($query),
        );
    }

    private function account(string $name): int
    {
        return (int) LedgerAccount::firstWhere('ledger_account_name', $name)->id;
    }

    /** Dr Purchases / Cr Accounts Payable. */
    private function post2(string $date, float $amount, string $narration = 'Test voucher'): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/journal-entries', [
            'journal_date' => $date,
            'journal_type' => 'general',
            'narration'    => $narration,
            'lines'        => [
                ['ledger_account_id' => $this->account('Purchases'), 'debit' => $amount, 'credit' => 0],
                ['ledger_account_id' => $this->account('Accounts Payable'), 'debit' => 0, 'credit' => $amount],
            ],
        ])->assertCreated();
    }

    private function assertMoney(float $expected, mixed $actual, string $message = ''): void
    {
        $this->assertSame($expected, (float) $actual, $message);
    }
}
