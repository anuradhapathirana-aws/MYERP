<?php

declare(strict_types=1);

namespace Modules\Finance\Enums;

/**
 * Lifecycle of a bank reconciliation: Draft -> Posted -> Voided.
 *
 * A draft holds the matching while the user works through the statement.
 * Posting freezes it: its realized lines are cleared for good and its
 * statement balance opens the next reconciliation. Voided is "undone": only
 * the latest posted reconciliation of an account may be undone, which puts
 * its lines back to uncleared.
 */
enum BankReconciliationStatus: string
{
    case Draft  = 'draft';
    case Posted = 'posted';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Draft  => 'Draft',
            self::Posted => 'Posted',
            self::Voided => 'Undone',
        };
    }

    /** @return list<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(fn (self $s): array => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
