<?php

declare(strict_types=1);

namespace Modules\Finance\Enums;

/**
 * How a ledger account behaves as a source of funds.
 *
 * NULL for the overwhelming majority of accounts — only the handful that money
 * actually flows through carry a value. Payment forms use this to decide which
 * accounts to offer: a Petty Cash Payment lists PettyCashBook accounts, a
 * Normal Payment lists CashBook and Bank accounts.
 *
 * This mirrors how Tally and QuickBooks model it — the instrument details sit
 * on the ledger itself, rather than in a separate House Bank master (SAP) or
 * bank Journal (Odoo). For a single-company deployment that extra layer buys
 * nothing: it exists to drive multi-bank automated payment runs.
 */
enum CashBookType: string
{
    case CashBook      = 'cash_book';
    case PettyCashBook = 'petty_cash_book';
    case Bank          = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::CashBook      => 'Cash Book',
            self::PettyCashBook => 'Petty Cash Book',
            self::Bank          => 'Bank',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(fn (self $t): array => [
            'value' => $t->value,
            'label' => $t->label(),
        ], self::cases());
    }
}
