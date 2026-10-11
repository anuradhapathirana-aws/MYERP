<?php

declare(strict_types=1);

namespace Modules\Finance\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Finance\DTOs\LedgerAccountData;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Models\AccountDefault;
use Modules\Finance\Models\ControlAccount;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\LedgerAccountService;

/**
 * Creates the ledger accounts a posting needs, and maps each one to its role.
 *
 * This is the answer to "how do we know which account each transaction uses".
 * Nobody works it out: the set is fixed by double-entry bookkeeping rather than
 * by any client's business, so the account and its mapping are created together
 * here and the system arrives knowing. Finance > Settings exists to SHOW the
 * mapping and handle a chart that genuinely differs, not to set it up.
 *
 * ── Why this is per-account idempotent, not run-once ─────────────────────────
 * FinanceChartOfAccountsSeeder aborts if any category exists, because a starter
 * chart only makes sense on a virgin database. Copying that guard here would
 * make this seeder skip on every installation that already has a chart --
 * including the one it is most needed on. So instead, per account:
 *
 *     control account missing          -> warn and skip, map nothing
 *     ledger account already exists    -> adopt it, create nothing
 *     ledger account absent            -> create it
 *     mapping row already present      -> leave it completely alone
 *
 * Safe to re-run any number of times. It adopts an account the client already
 * made with the right name instead of creating a second "Accounts Payable",
 * and it never overwrites a mapping someone deliberately changed.
 *
 * ── Bank accounts are not here ───────────────────────────────────────────────
 * Each one needs a real bank, branch and account number, so only the client can
 * create them -- from the Ledger Account screen, one per real account. They are
 * also deliberately absent from the mapping: the bank account is chosen per
 * payment on the Pay From dropdown, because the system genuinely cannot know
 * which of two accounts the money left.
 */
class FinanceGlAccountsSeeder extends Seeder
{
    /**
     * role => [control account to sit under, ledger account name]
     *
     * Every parent below is a control account the starter chart already
     * creates, so this fits a standard installation with nothing to adjust.
     *
     * Discount Allowed sits under "Sales Returns & Discounts" within Revenue,
     * i.e. as contra-revenue rather than an expense. That is the IFRS-preferred
     * treatment and it is what the starter chart's own structure implies --
     * AccountRole::acceptedTypes() allows either.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function plan(): array
    {
        return [
            AccountRole::AccountsPayable->value      => ['Trade & Other Payables',    'Accounts Payable'],
            AccountRole::AccountsReceivable->value   => ['Trade & Other Receivables', 'Accounts Receivable'],
            AccountRole::Purchases->value            => ['Direct Material Cost',      'Purchases'],
            AccountRole::SalesRevenue->value         => ['Sales Revenue',             'Sales Revenue'],
            AccountRole::OutputTaxPayable->value     => ['Statutory Payables',        'Output Tax Payable'],
            AccountRole::InputTaxRecoverable->value  => ['Advances & Prepayments',    'Input Tax Recoverable'],
            AccountRole::DiscountAllowed->value      => ['Sales Returns & Discounts', 'Discount Allowed'],
            AccountRole::DiscountReceived->value     => ['Other Operating Income',    'Discount Received'],
            AccountRole::SupplierAdvance->value      => ['Advances & Prepayments',    'Supplier Advances'],
            AccountRole::CustomerAdvance->value      => ['Trade & Other Payables',    'Customer Advances'],
            AccountRole::RoundingDifference->value   => ['Office & Establishment',    'Rounding Difference'],
            AccountRole::OpeningBalanceEquity->value => ['Retained Earnings',         'Opening Balance Equity'],

            // Mapped so the accounts exist and are visible, but no posting uses
            // them yet. GRNI waits on Bill Entry; Inventory and Cost of Sales
            // wait on perpetual stock valuation.
            AccountRole::BankCharges->value          => ['Bank Charges',              'Bank Charges'],
            AccountRole::GrniClearing->value         => ['Accrued Expenses',          'Goods Received Not Invoiced'],
            AccountRole::Inventory->value            => ['Inventories',               'Inventory'],
            AccountRole::CostOfSales->value          => ['Direct Material Cost',      'Cost of Sales'],
        ];
    }

    public function run(): void
    {
        $ledgerService = app(LedgerAccountService::class);

        $created = 0;
        $adopted = 0;
        $mapped  = 0;
        $kept    = 0;
        $skipped = [];

        foreach ($this->plan() as $roleValue => [$controlName, $ledgerName]) {
            $role = AccountRole::from($roleValue);

            $control = ControlAccount::with('category')
                ->where('control_account_name', $controlName)
                ->first();

            if ($control === null) {
                $skipped[] = "{$role->label()}: no control account named \"{$controlName}\"";
                continue;
            }

            // Validate the destination BEFORE creating anything, so a chart
            // whose structure does not match never produces a mapping that
            // AccountResolver would later refuse.
            $type = $control->category?->account_type;

            if ($type === null || ! in_array($type, $role->acceptedTypes(), true)) {
                $accepts = implode(' or ', array_map(
                    fn ($t): string => $t->label(),
                    $role->acceptedTypes(),
                ));

                $skipped[] = "{$role->label()}: \"{$controlName}\" is "
                    . ($type?->label() ?? 'untyped') . ", but this role needs {$accepts}";
                continue;
            }

            $ledger = LedgerAccount::where('control_account_id', $control->id)
                ->where('ledger_account_name', $ledgerName)
                ->first();

            if ($ledger === null) {
                $ledger = $ledgerService->create(new LedgerAccountData(
                    control_account_id:  $control->id,
                    ledger_account_name: $ledgerName,
                    description:         $role->hint(),
                    is_active:           true,
                    // Not a cash or bank account, so the whole cash block stays
                    // null. Only bank and cash accounts carry a company.
                    cash_book_type:      null,
                    company_id:          null,
                    allows_cash:         false,
                    allows_cheque:       false,
                    bank_id:             null,
                    bank_branch_id:      null,
                    bank_account_no:     null,
                ));

                $created++;
            } else {
                $adopted++;
            }

            // A mapping that already exists is left exactly as it is, including
            // one pointing somewhere else: that is a deliberate change by
            // whoever made it, and re-running a seeder must not undo it.
            $existing = AccountDefault::where('rule_key', $role->value)
                ->whereNull('company_id')
                ->first();

            if ($existing !== null) {
                $kept++;
                continue;
            }

            AccountDefault::create([
                'rule_key'          => $role->value,
                'ledger_account_id' => $ledger->id,
                'company_id'        => null,
                'description'       => $role->hint(),
            ]);

            $mapped++;
        }

        $this->command?->info(sprintf(
            'GL accounts: %d created, %d adopted. Mapping: %d written, %d already set.',
            $created,
            $adopted,
            $mapped,
            $kept,
        ));

        foreach ($skipped as $reason) {
            $this->command?->warn('Skipped - ' . $reason);
        }

        $this->reportReadiness();
    }

    /**
     * Say plainly what still has to be done by hand.
     *
     * A deployment that silently finishes with no bank account looks complete
     * but cannot post a single payment, so the gap is named here rather than
     * discovered later by a confirm() that fails.
     */
    private function reportReadiness(): void
    {
        $bankAccounts = LedgerAccount::whereNotNull('cash_book_type')
            ->where('cash_book_type', 'bank')
            ->count();

        if ($bankAccounts === 0) {
            $this->command?->warn(
                'No bank ledger accounts exist yet. Create one per real bank account under '
                . 'Finance > Ledger Accounts before confirming any payment or receipt - '
                . 'the Pay From dropdown reads from them, and only you know the account numbers.',
            );
        }
    }
}
