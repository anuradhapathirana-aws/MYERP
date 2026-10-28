<?php

declare(strict_types=1);

namespace Tests\Feature\Finance\Concerns;

use App\Models\SupplierGroup;
use App\Models\SupplierMaster;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Database\Seeders\FinanceChartOfAccountsSeeder;
use Modules\Finance\Database\Seeders\FinanceGlAccountsSeeder;
use Modules\Finance\DTOs\LedgerAccountData;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Enums\CashBookType;
use Modules\Finance\Models\AccountDefault;
use Modules\Finance\Models\Bill;
use Modules\Finance\Models\ControlAccount;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\JournalPostingService;
use Modules\Finance\Services\LedgerAccountService;
use Spatie\Permission\Models\Permission;

/**
 * Shared world for the Bill Entry tests: Finance on and posting immediately,
 * the seeded chart of accounts, two companies, a supplier with a 30-day
 * credit period, an expense account, and a fully-permitted user.
 */
trait BillFixtures
{
    protected User           $user;
    protected SupplierMaster $supplier;
    protected LedgerAccount  $expense;
    protected int            $companyA;
    protected int            $companyB;

    /** @var list<string> */
    protected array $billPermissions = [
        'view_bills', 'create_bills', 'edit_bills', 'delete_bills', 'confirm_bills', 'void_bills',
        'view_journal_entries', 'create_journal_entries', 'post_journal_entries', 'reverse_journal_entries',
        'view_finance_settings', 'manage_finance_settings',
    ];

    protected function setUpBills(): void
    {
        Carbon::setTestNow('2026-10-26 10:00:00');

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        app(SettingsService::class)->set('module.finance', true);
        app(SettingsService::class)->set(
            JournalPostingService::SETTING_POSTING_MODE,
            JournalPostingService::MODE_IMMEDIATE,
        );

        foreach ($this->billPermissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->user = $this->makeUser();

        $this->companyA = $this->makeCompany('Alpha (Pvt) Ltd');
        $this->companyB = $this->makeCompany('Beta (Pvt) Ltd');

        $this->seed(FinanceChartOfAccountsSeeder::class);
        $this->seed(FinanceGlAccountsSeeder::class);

        $group = SupplierGroup::firstOrCreate(
            ['name' => 'Services'],
            ['code' => 'SERV', 'is_active' => true],
        );

        $this->supplier = SupplierMaster::create([
            'supplier_code'     => 'SUP-0901',
            'supplier_name'     => 'Ceylon Electricity Board',
            'supplier_group_id' => $group->id,
            'credit_period'     => 30,
        ]);

        $this->expense = $this->ledger('Electricity Expense', 'Office & Establishment');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param list<string>|null $permissions */
    protected function makeUser(?array $permissions = null): User
    {
        $user = User::factory()->create(['active_modules' => ['finance']]);
        $user->givePermissionTo($permissions ?? $this->billPermissions);

        return $user;
    }

    protected function makeCompany(string $name): int
    {
        return (int) DB::table('inv_companies')->insertGetId([
            'company_name' => $name,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    protected function makeLocation(int $companyId, string $code): int
    {
        return (int) DB::table('inv_locations')->insertGetId([
            'company_id'             => $companyId,
            'industry_id'            => 1,
            'location_code'          => $code,
            'location_name'          => 'Location ' . $code,
            'country'                => 'LK',
            'loc_street_address'     => 'Street',
            'loc_city'               => 'City',
            'loc_country'            => 'LK',
            'loc_state'              => 'State',
            'loc_postal_zip_code'    => '00000',
            'financial_year'         => '2026',
            'stock_releasing_method' => 'FIFO',
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    protected function ledger(
        string $name,
        string $control,
        ?CashBookType $cashBook = null,
        ?int $companyId = null,
    ): LedgerAccount {
        $controlAccount = ControlAccount::where('control_account_name', $control)->firstOrFail();

        return app(LedgerAccountService::class)->create(new LedgerAccountData(
            control_account_id:  $controlAccount->id,
            ledger_account_name: $name,
            description:         null,
            is_active:           true,
            cash_book_type:      $cashBook,
            company_id:          $companyId,
            allows_cash:         $cashBook !== null,
            allows_cheque:       false,
            bank_id:             null,
            bank_branch_id:      null,
            bank_account_no:     null,
        ));
    }

    protected function mapped(AccountRole $role): int
    {
        return (int) AccountDefault::where('rule_key', $role->value)->whereNull('company_id')->value('ledger_account_id');
    }

    /**
     * @param  array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function billPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'request_id'       => (string) Str::uuid(),
            'supplier_id'      => $this->supplier->id,
            'transaction_date' => '2026-10-20',
            'document_no'      => 'CEB-1001',
            'document_date'    => '2026-10-18',
            'due_date'         => '2026-11-19',
            'company_id'       => $this->companyA,
            'location_id'      => null,
            'description'      => 'Electricity for October',
            'lines'            => [[
                'ledger_account_id' => $this->expense->id,
                'amount'            => '15000.50',
            ]],
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    protected function createBill(array $overrides = [], ?User $as = null): Bill
    {
        $id = $this->actingAs($as ?? $this->user)
            ->postJson('/api/v1/bills', $this->billPayload($overrides))
            ->assertCreated()
            ->json('data.id');

        return Bill::findOrFail($id);
    }

    /** @param array<string, mixed> $overrides */
    protected function confirmedBill(array $overrides = []): Bill
    {
        $bill = $this->createBill($overrides);

        $this->actingAs($this->user)->postJson("/api/v1/bills/{$bill->id}/confirm")->assertOk();

        return $bill->fresh();
    }
}
