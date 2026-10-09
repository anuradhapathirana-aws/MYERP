<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Enums\AccountType;
use Modules\Finance\Models\AccountCategory;
use Modules\Finance\Models\ControlAccount;
use Modules\Finance\Models\LedgerAccount;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * CRUD and validation for the three chart-of-accounts masters.
 *
 * The code-generation rules get their own file (AccountCodeGeneratorTest);
 * this one covers the endpoints.
 */
class ChartOfAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('module.finance', true);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [];
        foreach (['account_categories', 'control_accounts', 'ledger_accounts'] as $resource) {
            foreach (['view', 'create', 'edit', 'delete'] as $action) {
                $permissions[] = "{$action}_{$resource}";
            }
        }
        foreach ($permissions as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['active_modules' => ['finance']]);
        $this->user->givePermissionTo($permissions);
    }

    // ── Account Category ─────────────────────────────────────────────────────

    public function test_creating_a_category_generates_the_code_from_the_account_type(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/account-categories', [
            'account_type'  => 'equity',
            'category_name' => 'Capital & Reserves',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.code', '301')
            ->assertJsonPath('data.account_type_label', 'Equity')
            // Equity increases with a credit — surfaced so reporting never
            // re-derives it.
            ->assertJsonPath('data.normal_balance', 'credit');
    }

    public function test_code_is_ignored_when_supplied_by_the_client(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/account-categories', [
            'account_type'  => 'asset',
            'category_name' => 'Current Assets',
            'code'          => '999',
        ]);

        // The code is server-allocated and not fillable, so a forged value
        // can never reach the database.
        $response->assertCreated()->assertJsonPath('data.code', '101');
    }

    public function test_category_name_must_be_unique_within_its_account_type(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/account-categories', [
            'account_type' => 'asset', 'category_name' => 'Current',
        ])->assertCreated();

        $this->actingAs($this->user)->postJson('/api/v1/account-categories', [
            'account_type' => 'asset', 'category_name' => 'Current',
        ])->assertStatus(422)->assertJsonValidationErrors('category_name');

        // ...but the same name under a DIFFERENT type is legitimate.
        $this->actingAs($this->user)->postJson('/api/v1/account-categories', [
            'account_type' => 'liability', 'category_name' => 'Current',
        ])->assertCreated();
    }

    public function test_account_type_cannot_change_once_a_code_is_assigned(): void
    {
        $category = $this->makeCategory(AccountType::Asset, 'Current Assets');

        $response = $this->actingAs($this->user)->putJson("/api/v1/account-categories/{$category->id}", [
            'account_type'  => 'expense',
            'category_name' => 'Current Assets',
        ]);

        // Code 101 says "Assets". Allowing the type to change would make every
        // descendant code describe the wrong section of the chart.
        $response->assertStatus(422)->assertJsonValidationErrors('account_type');
        $this->assertSame(AccountType::Asset, $category->fresh()->account_type);
    }

    public function test_a_category_with_control_accounts_cannot_be_deleted(): void
    {
        $category = $this->makeCategory(AccountType::Asset, 'Current Assets');
        $this->makeControl($category, 'Cash & Cash Equivalents');

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/account-categories/{$category->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('id');

        $this->assertNotNull(AccountCategory::find($category->id));
    }

    public function test_an_empty_category_can_be_deleted(): void
    {
        $category = $this->makeCategory(AccountType::Asset, 'Current Assets');

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/account-categories/{$category->id}")
            ->assertNoContent();

        $this->assertNull(AccountCategory::find($category->id));
    }

    public function test_account_types_endpoint_lists_all_five_in_code_order(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/account-categories/account-types');

        $response->assertOk();
        $this->assertSame(
            ['Assets', 'Liabilities', 'Equity', 'Income', 'Expenses'],
            array_column($response->json('data'), 'label'),
            'Balance sheet types must precede P&L types so the chart sorts correctly by code.',
        );
        $this->assertSame([1, 2, 3, 4, 5], array_column($response->json('data'), 'digit'));
    }

    // ── Control Account ──────────────────────────────────────────────────────

    public function test_creating_a_control_account_extends_the_category_code(): void
    {
        $category = $this->makeCategory(AccountType::Asset, 'Current Assets');

        $this->actingAs($this->user)->postJson('/api/v1/control-accounts', [
            'account_category_id'  => $category->id,
            'control_account_name' => 'Cash & Cash Equivalents',
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', '10101')
            // The account type is derived from the parent, never stored twice.
            ->assertJsonPath('data.account_type', 'asset');
    }

    public function test_control_account_rejects_a_category_that_does_not_exist(): void
    {
        $this->actingAs($this->user)->postJson('/api/v1/control-accounts', [
            'account_category_id'  => 99999,
            'control_account_name' => 'Orphan',
        ])->assertStatus(422)->assertJsonValidationErrors('account_category_id');
    }

    public function test_control_account_cannot_be_reparented(): void
    {
        $assets  = $this->makeCategory(AccountType::Asset, 'Current Assets');
        $expense = $this->makeCategory(AccountType::Expense, 'Admin Expenses');
        $control = $this->makeControl($assets, 'Cash & Cash Equivalents');

        $this->actingAs($this->user)->putJson("/api/v1/control-accounts/{$control->id}", [
            'account_category_id'  => $expense->id,
            'control_account_name' => 'Cash & Cash Equivalents',
        ])->assertStatus(422)->assertJsonValidationErrors('account_category_id');

        $this->assertSame($assets->id, $control->fresh()->account_category_id);
    }

    // ── Ledger Account ───────────────────────────────────────────────────────

    public function test_creating_a_ledger_account_extends_the_control_code(): void
    {
        $control = $this->makeControl($this->makeCategory(AccountType::Asset, 'Current Assets'), 'Receivables');

        $this->actingAs($this->user)->postJson('/api/v1/ledger-accounts', [
            'control_account_id'  => $control->id,
            'ledger_account_name' => 'Trade Debtors',
        ])
            ->assertCreated()
            ->assertJsonPath('data.code', '10101001')
            // An ordinary account carries no cash/bank behaviour and belongs to
            // no single company — it is shared across the whole group.
            ->assertJsonPath('data.cash_book_type', null)
            ->assertJsonPath('data.company_id', null);
    }

    public function test_a_cash_book_must_accept_cash_or_cheques(): void
    {
        $control = $this->makeControl($this->makeCategory(AccountType::Asset, 'Current Assets'), 'Cash');
        $company = $this->makeCompany();

        $this->actingAs($this->user)->postJson('/api/v1/ledger-accounts', [
            'control_account_id'  => $control->id,
            'ledger_account_name' => 'Cash in Hand',
            'cash_book_type'      => 'cash_book',
            'company_id'          => $company,
            'allows_cash'         => false,
            'allows_cheque'       => false,
        ])->assertStatus(422)->assertJsonValidationErrors('allows_cash');
    }

    public function test_a_cash_or_bank_account_requires_an_owning_company(): void
    {
        $control = $this->makeControl($this->makeCategory(AccountType::Asset, 'Current Assets'), 'Cash');

        // The chart of accounts is shared across companies, but a cash box
        // belongs to exactly one legal entity.
        $this->actingAs($this->user)->postJson('/api/v1/ledger-accounts', [
            'control_account_id'  => $control->id,
            'ledger_account_name' => 'Cash in Hand',
            'cash_book_type'      => 'cash_book',
            'allows_cash'         => true,
        ])->assertStatus(422)->assertJsonValidationErrors('company_id');
    }

    public function test_an_ordinary_account_needs_no_company(): void
    {
        $control = $this->makeControl($this->makeCategory(AccountType::Expense, 'Admin Expenses'), 'Staff Costs');

        $this->actingAs($this->user)->postJson('/api/v1/ledger-accounts', [
            'control_account_id'  => $control->id,
            'ledger_account_name' => 'Salaries & Wages',
        ])->assertCreated()->assertJsonPath('data.company_id', null);
    }

    public function test_sub_fields_are_cleared_when_the_cash_type_does_not_use_them(): void
    {
        $control = $this->makeControl($this->makeCategory(AccountType::Asset, 'Current Assets'), 'Cash');
        $company = $this->makeCompany();

        $response = $this->actingAs($this->user)->postJson('/api/v1/ledger-accounts', [
            'control_account_id'  => $control->id,
            'ledger_account_name' => 'Petty Cash',
            'cash_book_type'      => 'petty_cash_book',
            'company_id'          => $company,
            // Sent but not applicable to a petty cash book — must not persist,
            // or a screen that never shows them would silently carry stale data.
            'allows_cash'         => true,
            'allows_cheque'       => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.allows_cash', false)
            ->assertJsonPath('data.allows_cheque', false);
    }

    public function test_ledger_account_cannot_be_reparented(): void
    {
        $category = $this->makeCategory(AccountType::Asset, 'Current Assets');
        $a        = $this->makeControl($category, 'Receivables');
        $b        = $this->makeControl($category, 'Cash');
        $ledger   = $this->makeLedger($a, 'Trade Debtors');

        $this->actingAs($this->user)->putJson("/api/v1/ledger-accounts/{$ledger->id}", [
            'control_account_id'  => $b->id,
            'ledger_account_name' => 'Trade Debtors',
        ])->assertStatus(422)->assertJsonValidationErrors('control_account_id');

        $this->assertSame($a->id, $ledger->fresh()->control_account_id);
    }

    public function test_fund_sources_filter_returns_only_money_bearing_accounts(): void
    {
        $category = $this->makeCategory(AccountType::Asset, 'Current Assets');
        $control  = $this->makeControl($category, 'Cash');
        $company  = $this->makeCompany();

        $this->makeLedger($control, 'Trade Debtors');
        $this->makeLedger($control, 'Cash in Hand', [
            'cash_book_type' => 'cash_book', 'company_id' => $company, 'allows_cash' => true,
        ]);
        $this->makeLedger($control, 'Petty Cash', [
            'cash_book_type' => 'petty_cash_book', 'company_id' => $company,
        ]);

        // A payment screen asks for fund sources rather than the whole chart.
        $all = $this->actingAs($this->user)
            ->getJson('/api/v1/ledger-accounts/all?fund_sources_only=1')
            ->assertOk()->json('data');

        $this->assertSame(['Cash in Hand', 'Petty Cash'], array_column($all, 'ledger_account_name'));

        // Petty Cash Payment narrows further.
        $petty = $this->actingAs($this->user)
            ->getJson('/api/v1/ledger-accounts/all?fund_sources_only=1&cash_book_type=petty_cash_book')
            ->assertOk()->json('data');

        $this->assertSame(['Petty Cash'], array_column($petty, 'ledger_account_name'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeCategory(AccountType $type, string $name): AccountCategory
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/account-categories', [
            'account_type' => $type->value, 'category_name' => $name,
        ])->assertCreated();

        return AccountCategory::findOrFail($response->json('data.id'));
    }

    private function makeControl(AccountCategory $category, string $name): ControlAccount
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/control-accounts', [
            'account_category_id' => $category->id, 'control_account_name' => $name,
        ])->assertCreated();

        return ControlAccount::findOrFail($response->json('data.id'));
    }

    /** @param array<string, mixed> $extra */
    private function makeLedger(ControlAccount $control, string $name, array $extra = []): LedgerAccount
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/ledger-accounts', array_merge([
            'control_account_id' => $control->id, 'ledger_account_name' => $name,
        ], $extra))->assertCreated();

        return LedgerAccount::findOrFail($response->json('data.id'));
    }

    private function makeCompany(): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('inv_companies')->insertGetId([
            'company_name' => 'Test Company (Pvt) Ltd',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }
}
