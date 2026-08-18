<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum CostingType: string
{
    case FOB = 'fob';
    case CIF = 'cif';

    public function label(): string
    {
        return match($this) {
            self::FOB => 'FOB (Free On Board)',
            self::CIF => 'CIF (Cost, Insurance & Freight)',
        };
    }

    public function shortLabel(): string
    {
        return match($this) {
            self::FOB => 'FOB',
            self::CIF => 'CIF',
        };
    }
}
