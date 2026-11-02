<?php

declare(strict_types=1);

namespace Modules\Finance\Enums;

/**
 * Lifecycle of a bill payment: Draft -> Confirmed -> Voided.
 *
 * Only a draft can be edited or deleted. A confirmed payment is in the books
 * and comes out again only by Void, which posts a reversal.
 */
enum BillPaymentStatus: string
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
