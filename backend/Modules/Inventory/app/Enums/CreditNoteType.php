<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum CreditNoteType: string
{
    case CustomerReturn = 'customer_return';
    case OverPayment    = 'over_payment';
    case Advance        = 'advance';

    public function label(): string
    {
        return match($this) {
            self::CustomerReturn => 'Customer Return',
            self::OverPayment    => 'Over Payment',
            self::Advance        => 'Advance',
        };
    }
}
