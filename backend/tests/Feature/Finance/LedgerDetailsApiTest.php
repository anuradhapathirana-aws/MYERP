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
 * Finance > General Ledger > Ledger Details: the General Ledger over a
 * selection of accounts, its options and its PDF, over HTTP.
 */
class LedgerDetailsApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/finance-reports/ledger-details';

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

    public function test_it_summarises_each_selected_account_with_opening_period_and_closing(): void
    {
        $this->post2('2026-03-15', 1000);   // before the period: b/f
        $this->post2('2026-04-20', 500);    // in the period

        $response = $this->report([
            'date_from'   => '2026-04-01',
            'account_ids' => [$this->account('Purchases'), $this->account('Accounts Payable')],
        ])->assertOk();

        $response->assertJsonCount(2, 'data.accounts')
            ->assertJsonPath('data.meta.total', 2);

        $purchases = $this->row($response, 'Purchases');
        $this->assertMoney(1000.0, $purchases['opening']);
        $this->assertMoney(500.0, $purchases['period_debit']);
        $this->assertMoney(0.0, $purchases['period_credit']);
        $this->assertMoney(1500.0, $purchases['closing']);
        $this->assertSame(1, $purchases['line_count']);
        $this->assertSame('debit', $purchases['normal_balance']);

        $payables = $this->row($response, 'Accounts Payable');
        $this->assertMoney(-1000.0, $payables['opening']);
        $this->assertMoney(-1500.0, $payables['closing']);

        // Grand totals: each pair on the side its balance lands, and they agree.
        $this->assertMoney(1000.0, $response->json('data.totals.opening_debit'));
        $this->assertMoney(1000.0, $response->json('data.totals.opening_credit'));
        $this->assertMoney(500.0, $response->json('data.totals.period_debit'));
        $this->assertMoney(500.0, $response->json('data.totals.period_credit'));
        $this->assertMoney(1500.0, $response->json('data.totals.closing_debit'));
        $this->assertMoney(1500.0, $response->json('data.totals.closing_credit'));
        $response->assertJsonPath('data.totals.account_count', 2)
            ->assertJsonPath('data.totals.line_count', 2);
    }

    public function test_each_account_agrees_with_its_drill_down(): void
    {
        $this->post2('2026-03-15', 1000);
        $this->post2('2026-04-20', 500);

        $filters = ['date_from' => '2026-04-01', 'date_to' => '2026-04-30'];
        $row     = $this->row($this->report($filters + ['account_ids' => [$this->account('Purchases')]]), 'Purchases');

        $drill = $this->actingAs($this->user)
            ->getJson('/api/v1/finance-reports/trial-balance/accounts/' . $this->account('Purchases') . '/transactions?' . http_build_query($filters))
            ->assertOk();

        $this->assertMoney((float) $drill->json('data.summary.opening'), $row['opening']);
        $this->assertMoney((float) $drill->json('data.summary.period_debit'), $row['period_debit']);
        $this->assertMoney((float) $drill->json('data.summary.closing'), $row['closing']);
    }

    public function test_no_selection_is_the_whole_general_ledger_with_activity_only(): void
    {
        $this->post2('2026-04-20', 500);

        $names = collect($this->report()->assertOk()->json('data.accounts'))->pluck('ledger_account_name');

        $this->assertEqualsCanonicalizing(['Purchases', 'Accounts Payable'], $names->all());

        // Asking for accounts with no activity lists the rest of the live chart too.
        $all = $this->report(['include_no_activity' => 1])->assertOk();
        $this->assertGreaterThan(2, $all->json('data.meta.total'));
        $this->assertTrue(collect($all->json('data.accounts'))->contains('line_count', 0));
        // Accounts with no activity add nothing to the grand totals.
        $this->assertMoney(500.0, $all->json('data.totals.period_debit'));
    }

    public function test_a_control_account_or_account_type_narrows_the_selection(): void
    {
        $this->post2('2026-04-20', 500);

        $purchases = LedgerAccount::query()->where('ledger_account_name', 'Purchases')->firstOrFail();

        $byControl = $this->report(['control_account_id' => $purchases->control_account_id])->assertOk();
        $this->assertSame(['Purchases'], collect($byControl->json('data.accounts'))->pluck('ledger_account_name')->all());

        $byType = $this->report(['account_type' => 'liability'])->assertOk();
        $this->assertSame(['Accounts Payable'], collect($byType->json('data.accounts'))->pluck('ledger_account_name')->all());
    }

    public function test_a_code_range_includes_every_code_starting_with_the_to_code(): void
    {
        $this->post2('2026-04-20', 500);

        // Liabilities start with 2, expenses with 5: "to 2" includes 2xxxxxxx.
        $response = $this->report(['code_from' => '2', 'code_to' => '2'])->assertOk();

        $this->assertSame(['Accounts Payable'], collect($response->json('data.accounts'))->pluck('ledger_account_name')->all());
    }

    public function test_party_accounts_can_be_left_out(): void
    {
        $this->post2('2026-04-20', 500);

        // Stand Accounts Payable in for a per-supplier account.
        DB::table('fin_ledger_accounts')->where('id', $this->account('Accounts Payable'))
            ->update(['party_type' => 'supplier', 'party_id' => 1]);

        $with = $this->report()->assertOk();
        $this->assertTrue($this->row($with, 'Accounts Payable')['is_party']);

        $without = $this->report(['include_parties' => 0])->assertOk();
        $this->assertSame(['Purchases'], collect($without->json('data.accounts'))->pluck('ledger_account_name')->all());
    }

    public function test_the_options_include_party_and_inactive_accounts(): void
    {
        DB::table('fin_ledger_accounts')->where('id', $this->account('Accounts Payable'))
            ->update(['party_type' => 'supplier', 'party_id' => 1, 'is_active' => false]);

        $response = $this->actingAs($this->user)->getJson(self::URL . '/options')->assertOk();

        $payables = collect($response->json('data.accounts'))->firstWhere('name', 'Accounts Payable');

        $this->assertNotNull($payables);
        $this->assertTrue($payables['is_party']);
        $this->assertFalse($payables['is_active']);
        $this->assertNotEmpty($response->json('data.control_accounts'));
        $this->assertNotEmpty($response->json('data.categories'));
        $this->assertCount(5, $response->json('data.account_types'));
    }

    public function test_drill_down_rows_carry_the_source_document_id(): void
    {
        $this->post2('2026-04-20', 500);

        $this->actingAs($this->user)
            ->getJson('/api/v1/finance-reports/trial-balance/accounts/' . $this->account('Purchases') . '/transactions')
            ->assertOk()
            ->assertJsonPath('data.rows.0.source_type', 'manual')
            ->assertJsonPath('data.rows.0.source_id', null);
    }

    public function test_the_pdf_downloads_in_both_layouts(): void
    {
        $this->post2('2026-03-15', 1000);
        $this->post2('2026-04-20', 500);

        foreach (['list', 't_account'] as $view) {
            $response = $this->actingAs($this->user)
                ->get(self::URL . '/pdf?' . http_build_query(['date_from' => '2026-04-01', 'view' => $view]))
                ->assertOk();

            $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        }
    }

    public function test_invalid_input_is_refused(): void
    {
        $this->report(['date_from' => '2026-04-10', 'date_to' => '2026-04-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');

        $this->report(['code_from' => "1'; DROP"])->assertUnprocessable()->assertJsonValidationErrors('code_from');
        $this->report(['account_type' => 'nonsense'])->assertUnprocessable()->assertJsonValidationErrors('account_type');
        $this->report(['account_ids' => range(1, 501)])->assertUnprocessable()->assertJsonValidationErrors('account_ids');

        $this->actingAs($this->user)
            ->get(self::URL . '/pdf?view=sideways', ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }

    public function test_it_needs_the_finance_reports_permission(): void
    {
        $other = User::factory()->create(['active_modules' => ['finance']]);

        $this->actingAs($other)->getJson(self::URL)->assertForbidden();
        $this->actingAs($other)->getJson(self::URL . '/options')->assertForbidden();
        $this->actingAs($other)->get(self::URL . '/pdf', ['Accept' => 'application/json'])->assertForbidden();
    }

    /** @param array<string, mixed> $query */
    private function report(array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->getJson(self::URL . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    /** @return array<string, mixed> */
    private function row(\Illuminate\Testing\TestResponse $response, string $name): array
    {
        $row = collect($response->json('data.accounts'))->firstWhere('ledger_account_name', $name);
        $this->assertNotNull($row, "{$name} is missing from the report.");

        return $row;
    }

    private function account(string $name): int
    {
        return (int) LedgerAccount::query()->where('ledger_account_name', $name)->value('id');
    }

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
