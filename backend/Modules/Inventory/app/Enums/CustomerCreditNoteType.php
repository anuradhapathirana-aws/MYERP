<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum CustomerCreditNoteType: string
{
    case SalesReturn = 'sales_return';
    case OverPayment = 'over_payment';
    case Advance     = 'advance';

    public function label(): string
    {
        return match($this) {
            self::SalesReturn => 'Sales Return',
            self::OverPayment => 'Over Payment',
            self::Advance     => 'Advance',
        };
    }
}
