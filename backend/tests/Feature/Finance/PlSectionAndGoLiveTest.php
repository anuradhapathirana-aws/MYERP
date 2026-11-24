<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Database\Seeders\FinanceChartOfAccountsSeeder;
use Modules\Finance\Enums\AccountType;
use Modules\Finance\Enums\PlSection;
use Modules\Finance\Models\AccountCategory;
use Modules\Finance\Services\FinanceControlService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Profit & Loss foundations: where each account category lands on the
 * statement (pl_section), and the Finance go-live date the statement starts at.
 */
class PlSectionAndGoLiveTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.finance', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view_account_categories', 'create_account_categories', 'edit_account_categories',
            'view_finance_settings', 'manage_finance_period_lock',
        ];
        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['finance']]);
        $this->user->givePermissionTo($permissions);
    }

    private function superAdmin(): User
    {
        Role::findOrCreate('super_admin', 'web');
        $it = User::factory()->create(['role' => UserRole::SuperAdmin, 'active_modules' => ['finance']]);
        $it->assignRole('super_admin');

        return $it;
    }

    // ── P&L section ──────────────────────────────────────────────────────────

    public function test_a_category_saves_a_section_that_suits_its_type(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/account-categories', [
            'account_type'  => 'expense',
            'category_name' => 'Marketing',
            'pl_section'    => 'selling_distribution',
        ])->assertCreated()
            ->assertJsonPath('data.pl_section', 'selling_distribution')
            ->assertJsonPath('data.pl_section_label', 'Selling & Distribution Expenses');
    }

    public function test_a_section_of_the_wrong_type_is_refused(): void
    {
        // Revenue is an income section; an expense category cannot take it.
        $this->actingAs($this->user)->postJson('/api/v1/account-categories', [
            'account_type'  => 'expense',
            'category_name' => 'Wages',
            'pl_section'    => 'revenue',
        ])->assertUnprocessable()->assertJsonValidationErrors('pl_section');

        // Assets never reach the P&L at all.
        $this->actingAs($this->user)->postJson('/api/v1/account-categories', [
            'account_type'  => 'asset',
            'category_name' => 'Fixed Assets',
            'pl_section'    => 'administration',
        ])->assertUnprocessable()->assertJsonValidationErrors('pl_section');
    }

    public function test_reserves_is_the_one_equity_section(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/account-categories', [
            'account_type'  => 'equity',
            'category_name' => 'Reserves',
            'pl_section'    => 'reserves',
        ])->assertCreated()->assertJsonPath('data.pl_section', 'reserves');
    }

    public function test_an_unclassified_category_reports_under_the_fallback_for_its_type(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/account-categories', [
            'account_type'  => 'expense',
            'category_name' => 'Sundry',
        ])->assertCreated();

        $response->assertJsonPath('data.pl_section', null)
            ->assertJsonPath('data.pl_section_label', 'Other Expenses');

        $asset = $this->actingAs($this->user)->postJson('/api/v1/account-categories', [
            'account_type'  => 'asset',
            'category_name' => 'Current Assets',
        ])->assertCreated();

        // A balance-sheet category has no P&L section at all.
        $asset->assertJsonPath('data.pl_section_label', null);
    }

    public function test_the_section_can_be_changed_and_cleared_on_edit(): void
    {
        $id = $this->actingAs($this->user)->postJson('/api/v1/account-categories', [
            'account_type'  => 'expense',
            'category_name' => 'Office',
            'pl_section'    => 'administration',
        ])->json('data.id');

        $this->actingAs($this->user)->putJson("/api/v1/account-categories/{$id}", [
            'account_type'  => 'expense',
            'category_name' => 'Office',
            'pl_section'    => 'finance',
        ])->assertOk()->assertJsonPath('data.pl_section', 'finance');

        $this->actingAs($this->user)->putJson("/api/v1/account-categories/{$id}", [
            'account_type'  => 'expense',
            'category_name' => 'Office',
            'pl_section'    => null,
        ])->assertOk()->assertJsonPath('data.pl_section', null);
    }

    public function test_the_sections_endpoint_lists_every_section_with_its_type(): void
    {
        $data = $this->actingAs($this->user)->getJson('/api/v1/account-categories/pl-sections')
            ->assertOk()->json('data');

        $this->assertSame(PlSection::values(), array_column($data, 'value'));
        $this->assertSame('income', collect($data)->firstWhere('value', 'revenue')['account_type']);
        $this->assertSame('equity', collect($data)->firstWhere('value', 'reserves')['account_type']);
    }

    public function test_the_starter_chart_arrives_classified(): void
    {
        (new FinanceChartOfAccountsSeeder())->run();

        $section = fn (string $name): ?PlSection => AccountCategory::where('category_name', $name)->first()?->pl_section;

        $this->assertSame(PlSection::Revenue, $section('Revenue'));
        $this->assertSame(PlSection::OtherIncome, $section('Other Income'));
        $this->assertSame(PlSection::CostOfSales, $section('Cost of Sales'));
        $this->assertSame(PlSection::Administration, $section('Administrative Expenses'));
        $this->assertSame(PlSection::SellingDistribution, $section('Selling & Distribution Expenses'));
        $this->assertSame(PlSection::Finance, $section('Finance Costs'));

        // "Capital & Reserves" also holds Share Capital, so it is NOT reserves.
        $this->assertNull($section('Capital & Reserves'));
        $this->assertNull($section('Current Assets'));
    }

    public function test_fallbacks_follow_the_account_type(): void
    {
        $this->assertSame(PlSection::OtherIncome, PlSection::fallbackFor(AccountType::Income));
        $this->assertSame(PlSection::OtherExpenses, PlSection::fallbackFor(AccountType::Expense));
        $this->assertNull(PlSection::fallbackFor(AccountType::Asset));
        $this->assertNull(PlSection::fallbackFor(AccountType::Equity));
    }

    // ── Go-live date ─────────────────────────────────────────────────────────

    public function test_the_go_live_date_is_super_admin_only(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/finance-settings/go-live-date', ['go_live_date' => '2026-12-01'])
            ->assertForbidden();

        $this->assertNull(app(FinanceControlService::class)->goLiveDate());
    }

    public function test_super_admin_sets_and_clears_it_and_every_change_is_audited(): void
    {
        $it = $this->superAdmin();

        // A future date is allowed: the cut-over is agreed before it happens.
        $this->actingAs($it)
            ->putJson('/api/v1/finance-settings/go-live-date', ['go_live_date' => '2026-12-01'])
            ->assertOk()->assertJsonPath('data.go_live_date', '2026-12-01');

        $this->actingAs($this->user)->getJson('/api/v1/finance-settings')
            ->assertJsonPath('data.go_live_date', '2026-12-01');

        $this->actingAs($it)
            ->putJson('/api/v1/finance-settings/go-live-date', ['go_live_date' => null])
            ->assertOk()->assertJsonPath('data.go_live_date', null);

        $this->assertNull(app(FinanceControlService::class)->goLiveDate());
        $this->assertSame(2, AuditLog::where('event', 'go_live_date_changed')->count());
    }

    public function test_a_malformed_date_is_refused(): void
    {
        $this->actingAs($this->superAdmin())
            ->putJson('/api/v1/finance-settings/go-live-date', ['go_live_date' => '01/12/2026'])
            ->assertUnprocessable()->assertJsonValidationErrors('go_live_date');
    }
}
