<?php

declare(strict_types=1);

namespace Modules\Finance\Enums;

/**
 * The document life of a supplier bill.
 *
 *   draft ---confirm---> confirmed ---void---> voided
 *
 * Confirm posts the journal; void reverses it on a chosen date. There is no
 * path back from voided, and a confirmed bill is never edited -- correcting
 * one means voiding it and entering it again, so the books show both.
 */
enum BillStatus: string
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

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** @return list<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(fn (self $s): array => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
