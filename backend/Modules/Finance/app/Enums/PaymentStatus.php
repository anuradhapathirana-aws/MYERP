<?php

declare(strict_types=1);

namespace Modules\Finance\Enums;

/**
 * How much of a payable document has been settled. Kept separate from the
 * document status: a bill is Confirmed AND Partly paid, never one or the other.
 *
 * Written only by Bill Payment (next feature) from its allocations. "Overdue"
 * is not stored -- it is derived from due_date at read time.
 */
enum PaymentStatus: string
{
    case Unpaid     = 'unpaid';
    case PartlyPaid = 'partly_paid';
    case Paid       = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid     => 'Unpaid',
            self::PartlyPaid => 'Partly Paid',
            self::Paid       => 'Paid',
        };
    }

    /** @return list<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(fn (self $s): array => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
