<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum CostingStatus: string
{
    case Draft     = 'draft';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match($this) {
            self::Draft     => 'Draft',
            self::Confirmed => 'Confirmed',
        };
    }
}
