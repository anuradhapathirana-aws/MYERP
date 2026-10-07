<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum ReturnCondition: string
{
    case Good    = 'good';
    case Damaged = 'damaged';

    public function label(): string
    {
        return match($this) {
            self::Good    => 'Good',
            self::Damaged => 'Damaged',
        };
    }
}
