<?php

declare(strict_types=1);

namespace Modules\Finance\Enums;

/**
 * Lifecycle of a bank deposit: Draft -> Confirmed -> Voided.
 *
 * Only a draft can be edited or deleted. A draft already holds its cheques
 * (one cheque, one slip), but moves no money; confirm posts the journal. A
 * confirmed deposit comes out again only by Void, which posts a reversal and
 * puts its cheques back in hand.
 */
enum BankDepositStatus: string
{
    case Draft     = 'draft';
    case Confirmed = 'confirmed';
    case Voided    = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Draft',
            self::Confirmed => 'Confirmed',
            self::Voided    => 'Voided',
        };
    }

    /** @return list<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(fn (self $s): array => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
