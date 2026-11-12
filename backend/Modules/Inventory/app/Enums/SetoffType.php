<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum SetoffType: string
{
    // Legacy: a manually typed set-off with no document behind it. No longer offered on
    // new payments — kept so payments confirmed before Supplier Returns still read.
    case CustomerReturn = 'customer_return';
    case SupplierReturn = 'supplier_return';
    case OverPayment    = 'over_payment';
    case Advance        = 'advance';

    public function label(): string
    {
        return match($this) {
            self::CustomerReturn => 'Customer Return',
            self::SupplierReturn => 'Supplier Return',
            self::OverPayment    => 'Over Payment',
            self::Advance        => 'Advance',
        };
    }
}
