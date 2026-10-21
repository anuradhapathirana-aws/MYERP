<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

/**
 * How much of an invoice's goods have come back on confirmed customer returns. Kept
 * apart from InvoiceStatus (the payment side): a Paid invoice can still be returned,
 * and the tag tells the user so without a lookup. Confirmed returns are final, so it
 * only ever moves forward: none → partial → full.
 */
enum InvoiceReturnStatus: string
{
    case None    = 'none';
    case Partial = 'partial';
    case Full    = 'full';

    public function label(): string
    {
        return match($this) {
            self::None    => '',
            self::Partial => 'Partly Returned',
            self::Full    => 'Fully Returned',
        };
    }
}
