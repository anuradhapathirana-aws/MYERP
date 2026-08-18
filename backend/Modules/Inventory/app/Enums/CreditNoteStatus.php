<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum CreditNoteStatus: string
{
    case Open      = 'open';
    case Exhausted = 'exhausted';

    public function label(): string
    {
        return match($this) {
            self::Open      => 'Open',
            self::Exhausted => 'Exhausted',
        };
    }
}
