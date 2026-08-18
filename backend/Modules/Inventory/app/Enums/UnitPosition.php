<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum UnitPosition: string
{
    case Prefix = 'prefix';
    case Suffix = 'suffix';

    public function label(): string
    {
        return match ($this) {
            self::Prefix => 'Prefix  (e.g. $100)',
            self::Suffix => 'Suffix  (e.g. 100 kg)',
        };
    }
}
