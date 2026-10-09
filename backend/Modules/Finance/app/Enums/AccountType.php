<?php

declare(strict_types=1);

namespace Modules\Finance\Enums;

/**
 * The five roots of the chart of accounts.
 *
 * The ORDER AND DIGIT ARE PART OF THE DATA MODEL, not a display preference:
 * every account code in the system begins with digit(), so changing a digit
 * later would orphan every existing code. The sequence follows the universal
 * accounting convention — balance sheet first in A = L + E order, then the
 * profit & loss accounts:
 *
 *   1 Assets       \
 *   2 Liabilities   >  Balance Sheet
 *   3 Equity       /
 *   4 Income       \
 *   5 Expenses     /   Profit & Loss
 *
 * Sorting the chart by code therefore produces a correctly ordered Trial
 * Balance and Balance Sheet with no special-case sort anywhere in reporting.
 */
enum AccountType: string
{
    case Asset     = 'asset';
    case Liability = 'liability';
    case Equity    = 'equity';
    case Income    = 'income';
    case Expense   = 'expense';

    /** First digit of every account code under this type. */
    public function digit(): int
    {
        return match ($this) {
            self::Asset     => 1,
            self::Liability => 2,
            self::Equity    => 3,
            self::Income    => 4,
            self::Expense   => 5,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Asset     => 'Assets',
            self::Liability => 'Liabilities',
            self::Equity    => 'Equity',
            self::Income    => 'Income',
            self::Expense   => 'Expenses',
        };
    }

    /**
     * The side on which this type normally carries its balance.
     *
     * Assets and Expenses increase with a debit; Liabilities, Equity and Income
     * increase with a credit. Journal Entry and the Trial Balance use this to
     * decide which column a balance belongs in, so it lives here rather than
     * being re-derived at each call site.
     */
    public function normalBalance(): string
    {
        return match ($this) {
            self::Asset, self::Expense => 'debit',
            default                    => 'credit',
        };
    }

    /** True for types that appear on the Balance Sheet rather than the P&L. */
    public function isBalanceSheet(): bool
    {
        return in_array($this, [self::Asset, self::Liability, self::Equity], true);
    }

    /** @return list<string> For validation rules: Rule::in(AccountType::values()) */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{value:string,label:string,digit:int,normal_balance:string}> */
    public static function options(): array
    {
        return array_map(fn (self $t): array => [
            'value'          => $t->value,
            'label'          => $t->label(),
            'digit'          => $t->digit(),
            'normal_balance' => $t->normalBalance(),
        ], self::cases());
    }
}
