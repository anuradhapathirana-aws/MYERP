<?php

declare(strict_types=1);

namespace Modules\Finance\Enums;

/**
 * The semantic roles a ledger account can play in a posting.
 *
 * This is the vocabulary Inventory speaks. A posting line asks for
 * AccountRole::AccountsPayable, never for account id 20101001 — which is what
 * keeps Inventory from having to read fin_ledger_accounts, and therefore what
 * keeps the two modules independently sellable.
 *
 * ── Why this is not a client decision ────────────────────────────────────────
 * The set of roles is fixed by double-entry bookkeeping, not by any client's
 * business. Every company that buys and sells needs payables, receivables,
 * revenue, tax and discount accounts. FinanceGlAccountsSeeder therefore
 * creates each ledger account AND its fin_account_defaults row together, so
 * the mapping arrives complete and nobody has to work it out.
 *
 * ── acceptedTypes() is the safety rail ───────────────────────────────────────
 * Mapping Accounts Payable to Sales Revenue would silently produce a wrong
 * Balance Sheet a month later. Each role declares which account types it will
 * accept, so that mistake is refused at save time with a 422 instead.
 *
 * Note DiscountAllowed and DiscountReceived accept TWO types. Both treatments
 * are legitimate — a settlement discount can be shown as contra-revenue
 * (reducing sales) or as an expense. The starter chart puts them under
 * "Sales Returns & Discounts" within Revenue, i.e. contra-revenue, which is
 * the IFRS-preferred treatment; pinning these to Expense alone would reject
 * the chart's own intent.
 */
enum AccountRole: string
{
    // ── Required for the four automated documents ────────────────────────────
    case AccountsPayable      = 'accounts_payable';
    case AccountsReceivable   = 'accounts_receivable';
    case Purchases            = 'purchases';
    case SalesRevenue         = 'sales_revenue';
    case OutputTaxPayable     = 'output_tax_payable';
    case InputTaxRecoverable  = 'input_tax_recoverable';
    case DiscountAllowed      = 'discount_allowed';
    case DiscountReceived     = 'discount_received';
    case SupplierAdvance      = 'supplier_advance';
    case CustomerAdvance      = 'customer_advance';
    case RoundingDifference   = 'rounding_difference';
    case OpeningBalanceEquity = 'opening_balance_equity';

    // ── Mapped but not yet used by any posting ───────────────────────────────
    case BankCharges          = 'bank_charges';
    case GrniClearing         = 'grni_clearing';
    case Inventory            = 'inventory';
    case CostOfSales          = 'cost_of_sales';

    public function label(): string
    {
        return match ($this) {
            self::AccountsPayable      => 'Accounts Payable',
            self::AccountsReceivable   => 'Accounts Receivable',
            self::Purchases            => 'Purchases',
            self::SalesRevenue         => 'Sales Revenue',
            self::OutputTaxPayable     => 'Output Tax Payable',
            self::InputTaxRecoverable  => 'Input Tax Recoverable',
            self::DiscountAllowed      => 'Discount Allowed',
            self::DiscountReceived     => 'Discount Received',
            self::SupplierAdvance      => 'Supplier Advances',
            self::CustomerAdvance      => 'Customer Advances',
            self::RoundingDifference   => 'Rounding Difference',
            self::OpeningBalanceEquity => 'Opening Balance Equity',
            self::BankCharges          => 'Bank Charges',
            self::GrniClearing         => 'Goods Received Not Invoiced',
            self::Inventory            => 'Inventory',
            self::CostOfSales          => 'Cost of Sales',
        };
    }

    /** Shown under the field on the Finance Settings screen. */
    public function hint(): string
    {
        return match ($this) {
            self::AccountsPayable      => 'What we owe suppliers. Credited by a GRN, cleared by a Supplier Payment.',
            self::AccountsReceivable   => 'What customers owe us. Debited by an Invoice, cleared by a Customer Receipt.',
            self::Purchases            => 'Cost of goods received, net of tax.',
            self::SalesRevenue         => 'Invoice value net of tax and line discounts.',
            self::OutputTaxPayable     => 'Tax collected from customers, owed to the revenue authority.',
            self::InputTaxRecoverable  => 'Tax paid to suppliers, reclaimable. Remittance is output minus input.',
            self::DiscountAllowed      => 'Settlement discount given to a customer at receipt time.',
            self::DiscountReceived     => 'Settlement discount taken from a supplier at payment time.',
            self::SupplierAdvance      => 'Money prepaid to a supplier, and supplier credit notes not yet used.',
            self::CustomerAdvance      => 'Money held for a customer: overpayments and advances.',
            self::RoundingDifference   => 'Absorbs sub-cent residue so the ledger agrees with the documents.',
            self::OpeningBalanceEquity => 'The balancing side of the opening-balance journal.',
            self::BankCharges          => 'Bank fees. Not yet posted by any document.',
            self::GrniClearing         => 'Used only once Bill Entry exists: a GRN credits this, the Bill moves it to Payables.',
            self::Inventory            => 'Used only if stock moves to perpetual valuation. Unused today.',
            self::CostOfSales          => 'Used only if stock moves to perpetual valuation. Unused today.',
        };
    }

    /**
     * The account types this role will accept.
     *
     * @return list<AccountType>
     */
    public function acceptedTypes(): array
    {
        return match ($this) {
            self::AccountsReceivable,
            self::InputTaxRecoverable,
            self::SupplierAdvance,
            self::Inventory            => [AccountType::Asset],

            self::AccountsPayable,
            self::OutputTaxPayable,
            self::CustomerAdvance,
            self::GrniClearing         => [AccountType::Liability],

            self::OpeningBalanceEquity => [AccountType::Equity],

            self::SalesRevenue         => [AccountType::Income],

            self::Purchases,
            self::BankCharges,
            self::CostOfSales          => [AccountType::Expense],

            // Both treatments are valid — see the class docblock.
            self::DiscountAllowed,
            self::DiscountReceived,
            self::RoundingDifference   => [AccountType::Income, AccountType::Expense],
        };
    }

    /**
     * False for roles no posting uses yet, so a missing mapping for them is
     * not an error. GRNI waits on Bill Entry; Inventory and Cost of Sales wait
     * on perpetual stock valuation.
     */
    public function isRequired(): bool
    {
        return ! in_array($this, [
            self::BankCharges,
            self::GrniClearing,
            self::Inventory,
            self::CostOfSales,
        ], true);
    }

    /** @return list<string> For validation: Rule::in(AccountRole::values()) */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<array{
     *     value:string, label:string, hint:string,
     *     accepted_types:list<string>, required:bool
     * }>
     */
    public static function options(): array
    {
        return array_map(fn (self $r): array => [
            'value'          => $r->value,
            'label'          => $r->label(),
            'hint'           => $r->hint(),
            'accepted_types' => array_map(fn (AccountType $t): string => $t->value, $r->acceptedTypes()),
            'required'       => $r->isRequired(),
        ], self::cases());
    }
}
