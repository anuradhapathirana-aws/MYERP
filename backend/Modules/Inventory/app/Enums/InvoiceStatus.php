<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum InvoiceStatus: string
{
    case Draft     = 'draft';
    case Issued    = 'issued';
    case Paid      = 'paid';
    /** Settled only by customer returns — no money was ever received. Set by the system, terminal. */
    case Returned  = 'returned';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Draft     => 'Draft',
            self::Issued    => 'Issued',
            self::Paid      => 'Paid',
            self::Returned  => 'Returned',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
