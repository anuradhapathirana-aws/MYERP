<?php

declare(strict_types=1);

namespace Modules\Finance\Enums;

/**
 * Where an account category lands on the Profit & Loss.
 *
 * AccountType says only "income" or "expense"; a P&L needs more than that:
 * revenue apart from other income, cost of sales apart from overheads, and
 * overheads split into administration, selling & distribution and finance.
 * This is the classification layer SAP keeps in a Financial Statement
 * Version, Odoo in account types and Tally in its primary groups.
 *
 * It lives on the CATEGORY because categories are already the "group" level of
 * the chart. A category without a section is never lost: an income category
 * falls back to Other Income and an expense category to Other Expenses
 * (fallbackFor()).
 *
 * Reserves is the one equity section: "Provision for Reserves" is an
 * appropriation of profit, read as the period's movement on reserve accounts.
 *
 * The order of cases is the order of the statement.
 */
enum PlSection: string
{
    case Revenue             = 'revenue';
    case CostOfSales         = 'cost_of_sales';
    case OtherIncome         = 'other_income';
    case Administration      = 'administration';
    case SellingDistribution = 'selling_distribution';
    case Finance             = 'finance';
    case OtherExpenses       = 'other_expenses';
    case Taxation            = 'taxation';
    case Reserves            = 'reserves';

    public function label(): string
    {
        return match ($this) {
            self::Revenue             => 'Revenue',
            self::CostOfSales         => 'Cost of Sales',
            self::OtherIncome         => 'Other Income',
            self::Administration      => 'Administration Expenses',
            self::SellingDistribution => 'Selling & Distribution Expenses',
            self::Finance             => 'Finance & Other Expenses',
            self::OtherExpenses       => 'Other Expenses',
            self::Taxation            => 'Taxation',
            self::Reserves            => 'Provision for Reserves',
        };
    }

    /** The one account type whose categories may carry this section. */
    public function accountType(): AccountType
    {
        return match ($this) {
            self::Revenue,
            self::OtherIncome => AccountType::Income,

            self::Reserves    => AccountType::Equity,

            default           => AccountType::Expense,
        };
    }

    /**
     * The section a category with no section of its own reports under. Null
     * for types that never appear on the P&L (assets, liabilities, and equity
     * other than reserves).
     */
    public static function fallbackFor(AccountType $type): ?self
    {
        return match ($type) {
            AccountType::Income  => self::OtherIncome,
            AccountType::Expense => self::OtherExpenses,
            default              => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<string> sections a category of this type may take */
    public static function valuesFor(AccountType $type): array
    {
        return array_values(array_map(
            fn (self $s): string => $s->value,
            array_filter(self::cases(), fn (self $s): bool => $s->accountType() === $type),
        ));
    }

    /** @return list<array{value:string,label:string,account_type:string}> */
    public static function options(): array
    {
        return array_map(fn (self $s): array => [
            'value'        => $s->value,
            'label'        => $s->label(),
            'account_type' => $s->accountType()->value,
        ], self::cases());
    }
}
