<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum InvoiceStatus: string
{
    case Draft     = 'draft';
    case Issued    = 'issued';
    case Paid      = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Draft     => 'Draft',
            self::Issued    => 'Issued',
            self::Paid      => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
