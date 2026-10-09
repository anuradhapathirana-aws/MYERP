<?php

declare(strict_types=1);

namespace Modules\Finance\Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;
use Modules\Finance\Enums\AccountType;
use Modules\Finance\Enums\CashBookType;
use Modules\Finance\Models\AccountCategory;
use Modules\Finance\Models\ControlAccount;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\AccountCategoryService;
use Modules\Finance\Services\ControlAccountService;
use Modules\Finance\Services\LedgerAccountService;
use Modules\Finance\DTOs\AccountCategoryData;
use Modules\Finance\DTOs\ControlAccountData;
use Modules\Finance\DTOs\LedgerAccountData;

/**
 * A standard starter chart of accounts.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────
 * Every ledger account must sit under a control account, and every control
 * account under a category. That rule is what keeps the Trial Balance a simple
 * roll-up — but on an empty database it would mean a new user has to invent
 * two levels of structure before they can record anything.
 *
 * Shipping a chart is how every major ERP resolves that: SAP ships country
 * charts (INT, CAGB, CAUS), Tally ships 28 predefined groups, Odoo installs a
 * localisation chart per country. The mandatory parent is painless precisely
 * because the parents are already there. This seeder is that chart.
 *
 * The structure below follows the conventional IFRS-style presentation used in
 * Sri Lanka: Balance Sheet (Assets, Liabilities, Equity) then Profit & Loss
 * (Income, Expenses), each ordered as it appears in the published accounts.
 *
 * ── Run it once, by name ─────────────────────────────────────────────────────
 *     php artisan db:seed --class="Modules\Finance\Database\Seeders\FinanceChartOfAccountsSeeder"
 *
 * It is deliberately NOT called from DatabaseSeeder. DatabaseSeeder re-runs on
 * every deployment, and this is not reference data like the bank list — it is
 * an opinionated starting point the client is expected to rename and extend.
 * Re-running it after someone renamed "Revenue" would recreate "Revenue"
 * alongside their version. The guard below makes that impossible: if any
 * account category exists, the seeder does nothing at all.
 *
 * Codes are NOT hardcoded here. They come from AccountCodeGenerator like any
 * other account, so this seeder exercises the same path the UI does.
 */
class FinanceChartOfAccountsSeeder extends Seeder
{
    /**
     * category name => [control account names, in order]
     *
     * Order is significant: codes are allocated sequentially, so the first
     * category under Assets becomes 101, the second 102, and so on.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const CHART = [
        AccountType::Asset->value => [
            'Non-Current Assets' => [
                'Property, Plant & Equipment',
                'Intangible Assets',
                'Investments',
            ],
            'Current Assets' => [
                'Inventories',
                'Trade & Other Receivables',
                'Cash & Cash Equivalents',
                'Advances & Prepayments',
            ],
        ],

        AccountType::Liability->value => [
            'Non-Current Liabilities' => [
                'Long Term Borrowings',
                'Retirement Benefit Obligations',
            ],
            'Current Liabilities' => [
                'Trade & Other Payables',
                'Short Term Borrowings',
                'Accrued Expenses',
                'Statutory Payables',
            ],
        ],

        AccountType::Equity->value => [
            'Capital & Reserves' => [
                'Share Capital',
                'Retained Earnings',
                'Reserves',
            ],
        ],

        AccountType::Income->value => [
            'Revenue' => [
                'Sales Revenue',
                'Sales Returns & Discounts',
            ],
            'Other Income' => [
                'Other Operating Income',
                'Finance Income',
            ],
        ],

        AccountType::Expense->value => [
            'Cost of Sales' => [
                'Direct Material Cost',
                'Direct Labour Cost',
                'Production Overheads',
            ],
            'Administrative Expenses' => [
                'Staff Costs',
                'Office & Establishment',
                'Professional Fees',
                'Depreciation & Amortisation',
            ],
            'Selling & Distribution Expenses' => [
                'Marketing & Promotion',
                'Freight & Delivery',
            ],
            'Finance Costs' => [
                'Interest Expense',
                'Bank Charges',
            ],
        ],
    ];

    public function run(): void
    {
        // Run-once guard. Anything already in the chart means this installation
        // has its own, and re-seeding would duplicate renamed accounts.
        if (AccountCategory::withTrashed()->exists()) {
            $this->command?->warn(
                'Chart of accounts is not empty — starter chart skipped. '
                . 'This seeder only runs on a brand new installation.',
            );

            return;
        }

        $categoryService = app(AccountCategoryService::class);
        $controlService  = app(ControlAccountService::class);

        $categoryCount = 0;
        $controlCount  = 0;

        foreach (self::CHART as $typeValue => $categories) {
            $type = AccountType::from($typeValue);

            foreach ($categories as $categoryName => $controlNames) {
                $category = $categoryService->create(new AccountCategoryData(
                    account_type:  $type,
                    category_name: $categoryName,
                    description:   null,
                    is_active:     true,
                ));
                $categoryCount++;

                foreach ($controlNames as $controlName) {
                    $controlService->create(new ControlAccountData(
                        account_category_id:  $category->id,
                        control_account_name: $controlName,
                        description:          null,
                        is_active:            true,
                    ));
                    $controlCount++;
                }
            }
        }

        $ledgerCount = $this->seedCashLedgers();

        $this->command?->info(
            "Starter chart of accounts created: {$categoryCount} categories, "
            . "{$controlCount} control accounts, {$ledgerCount} ledger accounts.",
        );
    }

    /**
     * The two cash accounts every business has.
     *
     * Bank ledger accounts are deliberately NOT seeded — each one needs a real
     * bank, branch and account number, which is specific to the installation.
     * These two need none of that.
     *
     * They DO need a company, though: a cash box and a petty cash float belong
     * to one legal entity, the same way a bank account does. On a database with
     * no company yet there is nothing truthful to point them at, so they are
     * skipped rather than guessed at — the user creates them from the Ledger
     * Account screen once their company exists. With exactly one company the
     * answer is unambiguous, so they are created against it.
     */
    private function seedCashLedgers(): int
    {
        $control = ControlAccount::where('control_account_name', 'Cash & Cash Equivalents')->first();

        if ($control === null) {
            return 0;
        }

        $companyId = Company::orderBy('id')->value('id');

        if ($companyId === null) {
            $this->command?->warn(
                'No company exists yet, so Cash in Hand and Petty Cash were not created. '
                . 'Add a company, then create them from Finance > Ledger Accounts.',
            );

            return 0;
        }

        $ledgerService = app(LedgerAccountService::class);

        $ledgers = [
            ['Cash in Hand', CashBookType::CashBook,      true,  true],
            ['Petty Cash',   CashBookType::PettyCashBook, false, false],
        ];

        foreach ($ledgers as [$name, $cashBookType, $allowsCash, $allowsCheque]) {
            $ledgerService->create(new LedgerAccountData(
                control_account_id:  $control->id,
                ledger_account_name: $name,
                description:         null,
                is_active:           true,
                cash_book_type:      $cashBookType,
                company_id:          $companyId,
                allows_cash:         $allowsCash,
                allows_cheque:       $allowsCheque,
                bank_id:             null,
                bank_branch_id:      null,
                bank_account_no:     null,
            ));
        }

        return LedgerAccount::count();
    }
}
