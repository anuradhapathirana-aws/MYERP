<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Contracts\StockValuationSource;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\Stock\NullStockValuationSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Finance\Database\Seeders\FinanceChartOfAccountsSeeder;
use Modules\Finance\Database\Seeders\FinanceGlAccountsSeeder;
use Modules\Finance\DTOs\AccountCategoryData;
use Modules\Finance\DTOs\ControlAccountData;
use Modules\Finance\DTOs\LedgerAccountData;
use Modules\Finance\Enums\AccountType;
use Modules\Finance\Enums\PlSection;
use Modules\Finance\Models\ControlAccount;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\AccountCategoryService;
use Modules\Finance\Services\ControlAccountService;
use Modules\Finance\Services\FinanceControlService;
use Modules\Finance\Services\LedgerAccountService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Finance > Reports > Profit & Loss, over HTTP.
 *
 * Journals are posted as vouchers against Opening Balance Equity (an equity
 * account outside the P&L), so only the P&L side of each one is under test.
 * Stock comes from a fake StockValuationSource, so these tests pin the
 * statement's arithmetic; Inventory's valuation has its own tests.
 */
class ProfitLossTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/finance-reports/profit-loss';

    private User $user;

    /** @var array<string, float> stock value by date, served by the fake */
    private array $stockValues = [];

    /** @var list<array{document_no: string, date: string, cost: float}> served by the fake */
    public array $uninvoiced = [];

    /** @var list<array<string, mixed>> the arguments the fake was asked with */
    public array $uninvoicedAsked = [];

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

        $this->fakeStock(available: true);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function fakeStock(bool $available): void
    {
        $test = $this;

        $this->app->instance(StockValuationSource::class, $available
            ? new class ($test) implements StockValuationSource {
                public function __construct(private readonly ProfitLossTest $test) {}

                public function isAvailable(): bool
                {
                    return true;
                }

                public function valuesAsAt(array $dates, ?int $companyId = null, ?int $locationId = null, ?array $allowedCompanyIds = null): array
                {
                    $out = [];
                    foreach ($dates as $d) {
                        $out[$d] = $this->test->stockOn($d);
                    }

                    return $out;
                }

                public function uninvoicedDeliveries(string $from, string $to, ?int $companyId = null, ?int $locationId = null, ?array $allowedCompanyIds = null): array
                {
                    $this->test->uninvoicedAsked[] = compact('from', 'to', 'companyId', 'locationId');

                    return $this->test->uninvoiced;
                }

                public function basis(): string
                {
                    return 'fake';
                }
            }
            : new NullStockValuationSource());
    }

    /** Stock at the end of $date: the latest value set on or before it. */
    public function stockOn(string $date): float
    {
        $value = 0.0;
        ksort($this->stockValues);
        foreach ($this->stockValues as $d => $v) {
            if ($d <= $date) {
                $value = $v;
            }
        }

        return $value;
    }

    private function account(string $name): int
    {
        $id = (int) LedgerAccount::query()->where('ledger_account_name', $name)->value('id');
        $this->assertGreaterThan(0, $id, "{$name} is missing from the chart.");

        return $id;
    }

    /** A new ledger account under an existing control account. */
    private function newAccount(string $control, string $name): int
    {
        return app(LedgerAccountService::class)->create(new LedgerAccountData(
            control_account_id:  (int) ControlAccount::where('control_account_name', $control)->value('id'),
            ledger_account_name: $name,
            description:         null,
            is_active:           true,
            cash_book_type:      null,
            company_id:          null,
            allows_cash:         false,
            allows_cheque:       false,
            bank_id:             null,
            bank_branch_id:      null,
            bank_account_no:     null,
        ))->id;
    }

    /** A category -> control -> ledger branch of its own. */
    private function branch(AccountType $type, string $category, ?PlSection $section, string $ledger): int
    {
        $cat = app(AccountCategoryService::class)->create(new AccountCategoryData(
            account_type: $type, category_name: $category, description: null, is_active: true, pl_section: $section,
        ));
        $ctrl = app(ControlAccountService::class)->create(new ControlAccountData(
            account_category_id: $cat->id, control_account_name: $category . ' Control', description: null, is_active: true,
        ));

        return $this->newAccount($ctrl->control_account_name, $ledger);
    }

    /** Debit (positive) or credit (negative) $accountId, balanced by Opening Balance Equity. */
    private function jv(string $date, int $accountId, float $amount): void
    {
        $equity = $this->account('Opening Balance Equity');

        $this->actingAs($this->user)->postJson('/api/v1/journal-entries', [
            'journal_date' => $date,
            'journal_type' => 'general',
            'narration'    => 'Test',
            'lines'        => [
                ['ledger_account_id' => $accountId, 'debit' => max($amount, 0), 'credit' => max(-$amount, 0)],
                ['ledger_account_id' => $equity, 'debit' => max(-$amount, 0), 'credit' => max($amount, 0)],
            ],
        ])->assertCreated();
    }

    private function report(array $query): TestResponse
    {
        return $this->actingAs($this->user)->getJson(self::URL . '?' . http_build_query($query));
    }

    private function money(TestResponse $r, string $path): float
    {
        return (float) $r->json("data.{$path}");
    }

    /** One trading month: sales, returns, purchases, overheads, tax. */
    private function postNovember(): void
    {
        $this->jv('2026-11-05', $this->account('Sales Revenue'), -100000);
        $this->jv('2026-11-06', $this->account('Sales Returns'), 5000);
        $this->jv('2026-11-07', $this->account('Purchases'), 60000);
        $this->jv('2026-11-08', $this->account('Purchase Returns'), -2000);
        $this->jv('2026-11-09', $this->account('Discount Received'), -1000);
        $this->jv('2026-11-10', $this->account('Rounding Difference'), 300);  // Office & Establishment: admin
        $this->jv('2026-11-11', $this->account('Bank Charges'), 200);         // Finance Costs
    }

    // ── The statement ────────────────────────────────────────────────────────

    public function test_the_statement_follows_the_trading_account_format(): void
    {
        $this->postNovember();
        $this->stockValues = ['2026-10-31' => 10000.0, '2026-11-30' => 15000.0];

        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30'])->assertOk();

        // Revenue is net of returns (contra-revenue in the Revenue category).
        $this->assertSame(95000.0, $this->money($r, 'revenue.total.amount'));

        // COGS = 10,000 + (60,000 − 2,000) − 15,000.
        $this->assertSame(10000.0, $this->money($r, 'cost_of_sales.opening_stock.amount'));
        $this->assertSame(58000.0, $this->money($r, 'cost_of_sales.purchases.total.amount'));
        $this->assertSame(15000.0, $this->money($r, 'cost_of_sales.closing_stock.amount'));
        $this->assertSame(53000.0, $this->money($r, 'cost_of_sales.total.amount'));

        $this->assertSame(42000.0, $this->money($r, 'gross_profit.amount'));
        $this->assertSame(1000.0, $this->money($r, 'other_income.total.amount'));
        $this->assertSame(500.0, $this->money($r, 'overheads.total.amount'));

        // 42,000 + 1,000 − 500.
        $this->assertSame(42500.0, $this->money($r, 'net_before_tax.amount'));
        $this->assertSame(42500.0, $this->money($r, 'net_for_period.amount'));

        $groups = collect($r->json('data.overheads.groups'))->keyBy('key');
        $this->assertSame(300.0, (float) $groups['administration']['total']['amount']);
        $this->assertSame(200.0, (float) $groups['finance']['total']['amount']);
    }

    public function test_purchases_are_found_by_role_wherever_they_sit(): void
    {
        $this->jv('2026-11-07', $this->account('Purchases'), 60000);

        $names = collect($this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30'])
            ->json('data.cost_of_sales.purchases.lines'))->pluck('name');

        $this->assertSame(['Purchases'], $names->all());
    }

    public function test_other_direct_costs_sit_inside_cost_of_sales(): void
    {
        $labour = $this->newAccount('Direct Labour Cost', 'Factory Wages');
        $this->jv('2026-11-10', $labour, 4000);

        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30']);

        $this->assertSame(4000.0, $this->money($r, 'cost_of_sales.direct_costs.total.amount'));
        $this->assertSame(4000.0, $this->money($r, 'cost_of_sales.total.amount'));
        $this->assertSame(-4000.0, $this->money($r, 'gross_profit.amount'));
    }

    public function test_taxation_and_reserves_come_from_their_sections(): void
    {
        $this->jv('2026-11-05', $this->account('Sales Revenue'), -50000);
        $tax      = $this->branch(AccountType::Expense, 'Income Tax', PlSection::Taxation, 'Income Tax Expense');
        $reserves = $this->branch(AccountType::Equity, 'Reserves', PlSection::Reserves, 'General Reserve');

        $this->jv('2026-11-30', $tax, 15000);
        // Transfer to reserve: credit the reserve.
        $this->jv('2026-11-30', $reserves, -5000);

        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30']);

        $this->assertSame(50000.0, $this->money($r, 'net_before_tax.amount'));
        $this->assertSame(15000.0, $this->money($r, 'taxation.total.amount'));
        $this->assertSame(35000.0, $this->money($r, 'net_after_tax.amount'));
        $this->assertSame(5000.0, $this->money($r, 'reserves.total.amount'));
        $this->assertSame(30000.0, $this->money($r, 'net_for_period.amount'));
    }

    public function test_balance_sheet_accounts_never_appear(): void
    {
        $this->jv('2026-11-05', $this->account('Sales Revenue'), -1000);

        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30']);
        $all = json_encode($r->json('data'));

        // The balancing Opening Balance Equity line is not a P&L account.
        $this->assertStringNotContainsString('Opening Balance Equity', $all);
        $this->assertSame(1000.0, $this->money($r, 'net_for_period.amount'));
    }

    public function test_an_unclassified_category_reports_under_the_fallback_and_is_flagged(): void
    {
        $misc = $this->branch(AccountType::Expense, 'Sundry Costs', null, 'Sundry');
        $this->jv('2026-11-12', $misc, 700);

        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30']);

        $groups = collect($r->json('data.overheads.groups'))->keyBy('key');
        $this->assertSame(700.0, (float) $groups['other_expenses']['total']['amount']);

        $warning = collect($r->json('data.warnings'))->firstWhere('code', 'unclassified_categories');
        $this->assertNotNull($warning);
        $this->assertStringContainsString('Sundry Costs', $warning['message']);
    }

    public function test_only_the_period_counts(): void
    {
        $this->jv('2026-10-31', $this->account('Sales Revenue'), -999);
        $this->jv('2026-11-01', $this->account('Sales Revenue'), -100);
        $this->jv('2026-11-30', $this->account('Sales Revenue'), -200);
        $this->jv('2026-12-01', $this->account('Sales Revenue'), -999);

        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30']);

        $this->assertSame(300.0, $this->money($r, 'revenue.total.amount'));
    }

    public function test_revenue_agrees_with_the_trial_balance(): void
    {
        $this->postNovember();

        $q  = ['date_from' => '2026-11-01', 'date_to' => '2026-11-30'];
        $tb = collect($this->actingAs($this->user)->getJson('/api/v1/finance-reports/trial-balance?' . http_build_query($q))
            ->json('data.rows'))->firstWhere('ledger_account_name', 'Sales Revenue');

        $line = collect($this->report($q)->json('data.revenue.lines'))->firstWhere('name', 'Sales Revenue');

        $this->assertSame((float) $tb['credit_balance'], (float) $line['amount']);
    }

    // ── Stock ────────────────────────────────────────────────────────────────

    public function test_opening_stock_is_valued_the_day_before_and_closing_on_the_last_day(): void
    {
        $this->stockValues = ['2026-10-31' => 1000.0, '2026-11-01' => 9999.0, '2026-11-30' => 2000.0];

        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30']);

        $r->assertJsonPath('data.cost_of_sales.opening_stock.as_at', '2026-10-31')
            ->assertJsonPath('data.cost_of_sales.closing_stock.as_at', '2026-11-30');
        $this->assertSame(1000.0, $this->money($r, 'cost_of_sales.opening_stock.amount'));
        $this->assertSame(2000.0, $this->money($r, 'cost_of_sales.closing_stock.amount'));
    }

    public function test_a_typed_stock_figure_replaces_the_automatic_one(): void
    {
        $this->stockValues = ['2026-10-31' => 1000.0, '2026-11-30' => 2000.0];

        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30', 'closing_stock' => 2500]);

        $this->assertSame(2500.0, $this->money($r, 'cost_of_sales.closing_stock.amount'));
        $this->assertSame(2000.0, $this->money($r, 'cost_of_sales.closing_stock.auto'));
        $r->assertJsonPath('data.cost_of_sales.closing_stock.overridden', true)
            ->assertJsonPath('data.cost_of_sales.opening_stock.overridden', false);

        // 1,000 − 2,500: COGS goes negative with no purchases, profit up by it.
        $this->assertSame(1500.0, $this->money($r, 'gross_profit.amount'));
    }

    public function test_without_inventory_stock_is_entered_by_hand(): void
    {
        $this->fakeStock(available: false);

        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30']);

        $this->assertSame(0.0, $this->money($r, 'cost_of_sales.opening_stock.amount'));
        $r->assertJsonPath('data.cost_of_sales.opening_stock.auto', null)
            ->assertJsonPath('data.stock_source.available', false);
        $this->assertNotNull(collect($r->json('data.warnings'))->firstWhere('code', 'stock_manual'));

        $typed = $this->report([
            'date_from' => '2026-11-01', 'date_to' => '2026-11-30', 'opening_stock' => 300, 'closing_stock' => 100,
        ]);
        $this->assertSame(200.0, $this->money($typed, 'cost_of_sales.total.amount'));
        $this->assertNull(collect($typed->json('data.warnings'))->firstWhere('code', 'stock_manual'));
    }

    public function test_negative_stock_is_refused(): void
    {
        $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30', 'opening_stock' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors('opening_stock');
    }

    // ── Comparative period ───────────────────────────────────────────────────

    public function test_whole_months_compare_with_the_months_before(): void
    {
        $this->jv('2026-10-15', $this->account('Sales Revenue'), -400);
        $this->jv('2026-11-15', $this->account('Sales Revenue'), -600);
        $this->stockValues = ['2026-09-30' => 50.0, '2026-10-31' => 70.0, '2026-11-30' => 90.0];

        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30', 'compare' => 1])->assertOk();

        $r->assertJsonPath('data.comparative.from', '2026-10-01')
            ->assertJsonPath('data.comparative.to', '2026-10-31')
            ->assertJsonPath('data.comparative.available', true);

        $this->assertSame(600.0, $this->money($r, 'revenue.total.amount'));
        $this->assertSame(400.0, $this->money($r, 'revenue.total.compare'));

        // October's own stock dates: opening 30 Sep, closing 31 Oct.
        $this->assertSame(50.0, $this->money($r, 'cost_of_sales.opening_stock.compare'));
        $this->assertSame(70.0, $this->money($r, 'cost_of_sales.closing_stock.compare'));
        // 400 − (50 − 70).
        $this->assertSame(420.0, $this->money($r, 'net_for_period.compare'));
    }

    public function test_a_quarter_compares_with_the_quarter_before(): void
    {
        $r = $this->report(['date_from' => '2026-10-01', 'date_to' => '2026-12-31', 'compare' => 1]);

        $r->assertJsonPath('data.comparative.from', '2026-07-01')
            ->assertJsonPath('data.comparative.to', '2026-09-30');
    }

    public function test_an_odd_range_compares_with_the_same_number_of_days(): void
    {
        $r = $this->report(['date_from' => '2026-11-11', 'date_to' => '2026-11-20', 'compare' => 1]);

        $r->assertJsonPath('data.comparative.from', '2026-11-01')
            ->assertJsonPath('data.comparative.to', '2026-11-10');
    }

    public function test_the_comparative_dates_can_be_chosen(): void
    {
        $this->jv('2025-11-15', $this->account('Sales Revenue'), -250);

        $r = $this->report([
            'date_from' => '2026-11-01', 'date_to' => '2026-11-30',
            'compare' => 1, 'compare_from' => '2025-11-01', 'compare_to' => '2025-11-30',
        ]);

        $r->assertJsonPath('data.comparative.from', '2025-11-01');
        $this->assertSame(250.0, $this->money($r, 'revenue.total.compare'));
    }

    public function test_without_compare_there_is_no_comparative_column(): void
    {
        $this->jv('2026-11-15', $this->account('Sales Revenue'), -600);

        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30']);

        $r->assertJsonPath('data.comparative.enabled', false)
            ->assertJsonPath('data.revenue.total.compare', null)
            ->assertJsonPath('data.net_for_period.compare', null);
    }

    // ── Go-live date ─────────────────────────────────────────────────────────

    public function test_a_period_before_go_live_is_refused(): void
    {
        app(FinanceControlService::class)->setGoLiveDate('2026-11-01');

        $this->report(['date_from' => '2026-10-01', 'date_to' => '2026-11-30'])
            ->assertUnprocessable()->assertJsonValidationErrors('date_from');

        $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30'])->assertOk();
    }

    public function test_a_comparative_before_go_live_is_marked_not_available(): void
    {
        app(FinanceControlService::class)->setGoLiveDate('2026-11-01');

        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30', 'compare' => 1])->assertOk();

        $r->assertJsonPath('data.comparative.enabled', true)
            ->assertJsonPath('data.comparative.available', false)
            ->assertJsonPath('data.revenue.total.compare', null);
        $this->assertStringContainsString('01-11-2026', (string) $r->json('data.comparative.reason'));
    }

    public function test_no_go_live_date_is_warned(): void
    {
        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30']);

        $this->assertNotNull(collect($r->json('data.warnings'))->firstWhere('code', 'go_live_not_set'));

        app(FinanceControlService::class)->setGoLiveDate('2026-01-01');
        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30']);
        $this->assertNull(collect($r->json('data.warnings'))->firstWhere('code', 'go_live_not_set'));
    }

    public function test_deliveries_not_invoiced_by_the_period_end_are_warned(): void
    {
        $this->uninvoiced = [
            ['document_no' => 'DO-2026-0051', 'date' => '2026-11-23', 'cost' => 37294.32],
            ['document_no' => 'DO-2026-0053', 'date' => '2026-11-24', 'cost' => 100.01],
        ];

        $r = $this->report(['date_from' => '2026-11-23', 'date_to' => '2026-11-24']);

        $w = collect($r->json('data.warnings'))->firstWhere('code', 'uninvoiced_deliveries');
        $this->assertNotNull($w);
        $this->assertStringStartsWith('2 deliveries in this period are not invoiced by 24-11-2026 (DO-2026-0051, DO-2026-0053)', $w['message']);
        $this->assertStringContainsString('cost 37,394.33', $w['message']);
        $this->assertCount(2, $w['documents']);

        // Asked for the current period only, in the scope of the report.
        $this->assertSame([['from' => '2026-11-23', 'to' => '2026-11-24', 'companyId' => null, 'locationId' => null]], $this->uninvoicedAsked);
    }

    public function test_a_location_filter_warns_about_lines_with_no_location(): void
    {
        $this->jv('2026-11-05', $this->account('Sales Revenue'), -100000);
        $this->jv('2026-11-06', $this->account('Purchases'), 60000);
        $this->jv('2026-12-01', $this->account('Sales Revenue'), -500);   // outside the period

        // Whole company: nothing is left out, so no warning.
        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30']);
        $this->assertNull(collect($r->json('data.warnings'))->firstWhere('code', 'lines_without_location'));

        // One location: the two P&L lines (not the equity side) have none.
        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30', 'location_id' => 7]);
        $this->assertSame(0.0, $this->money($r, 'revenue.total.amount'));
        $w = collect($r->json('data.warnings'))->firstWhere('code', 'lines_without_location');
        $this->assertStringStartsWith('2 income / expense journal line(s)', $w['message']);

        // Tagged lines are not counted.
        DB::table('fin_journal_lines')->update(['location_id' => 7]);
        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30', 'location_id' => 7]);
        $this->assertNull(collect($r->json('data.warnings'))->firstWhere('code', 'lines_without_location'));
        $this->assertSame(100000.0, $this->money($r, 'revenue.total.amount'));
    }

    public function test_many_uninvoiced_deliveries_are_listed_briefly(): void
    {
        foreach (range(1, 7) as $i) {
            $this->uninvoiced[] = ['document_no' => "DO-{$i}", 'date' => '2026-11-10', 'cost' => 10.0];
        }

        $w = collect($this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30'])->json('data.warnings'))
            ->firstWhere('code', 'uninvoiced_deliveries');

        $this->assertStringContainsString('(DO-1, DO-2, DO-3, DO-4, DO-5 and 2 more)', $w['message']);
        $this->assertStringStartsWith('7 deliveries', $w['message']);
    }

    public function test_no_uninvoiced_warning_when_everything_is_invoiced_or_inventory_is_off(): void
    {
        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30']);
        $this->assertNull(collect($r->json('data.warnings'))->firstWhere('code', 'uninvoiced_deliveries'));

        $this->fakeStock(available: false);
        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30']);
        $this->assertNull(collect($r->json('data.warnings'))->firstWhere('code', 'uninvoiced_deliveries'));
    }

    // ── Drafts, validation, access ───────────────────────────────────────────

    public function test_draft_journals_are_excluded_and_counted(): void
    {
        $entry = DB::table('fin_journal_entries')->insertGetId([
            'journal_no' => 'JV-DRAFT', 'journal_date' => '2026-11-15', 'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fin_journal_lines')->insert([
            'journal_entry_id' => $entry, 'ledger_account_id' => $this->account('Sales Revenue'),
            'debit' => 0, 'credit' => 5000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->report(['date_from' => '2026-11-01', 'date_to' => '2026-11-30']);

        $this->assertSame(0.0, $this->money($r, 'revenue.total.amount'));
        $warning = collect($r->json('data.warnings'))->firstWhere('code', 'draft_journals');
        $this->assertStringStartsWith('1 draft', $warning['message']);
    }

    public function test_both_dates_are_required(): void
    {
        $this->report(['date_to' => '2026-11-30'])->assertUnprocessable()->assertJsonValidationErrors('date_from');
        $this->report(['date_from' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->report(['date_from' => '2026-11-30', 'date_to' => '2026-11-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('date_to');
    }

    public function test_it_needs_the_finance_reports_permission(): void
    {
        $clerk = User::factory()->create(['active_modules' => ['finance']]);

        $this->actingAs($clerk)->getJson(self::URL . '?date_from=2026-11-01&date_to=2026-11-30')->assertForbidden();
    }

    public function test_the_ledger_query_count_does_not_grow_with_journals(): void
    {
        $this->postNovember();
        $q = ['date_from' => '2026-11-01', 'date_to' => '2026-11-30', 'compare' => 1];

        // The first request also warms the permission and settings caches.
        $this->report($q)->assertOk();

        DB::enableQueryLog();
        $this->report($q)->assertOk();
        $few = count(DB::getQueryLog());

        $this->postNovember();
        $this->postNovember();

        DB::flushQueryLog();
        $this->report($q)->assertOk();

        $this->assertSame($few, count(DB::getQueryLog()));
    }

    // ── PDF ──────────────────────────────────────────────────────────────────

    public function test_the_pdf_downloads_with_the_same_parameters(): void
    {
        $this->postNovember();

        $response = $this->actingAs($this->user)
            ->get(self::URL . '/pdf?date_from=2026-11-01&date_to=2026-11-30&compare=1&closing_stock=1500')
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('Profit_and_Loss_2026-11-01_to_2026-11-30.pdf', (string) $response->headers->get('content-disposition'));
    }

    public function test_the_pdf_refuses_a_period_before_go_live(): void
    {
        app(FinanceControlService::class)->setGoLiveDate('2026-11-01');

        $this->actingAs($this->user)
            ->getJson(self::URL . '/pdf?date_from=2026-10-01&date_to=2026-10-31')
            ->assertUnprocessable();
    }
}
