<?php

declare(strict_types=1);

namespace App\Enums;

/** Which side of the business a tax code is picked on. Sales codes come later. */
enum TaxCodeScope: string
{
    case Purchase = 'purchase';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Purchases',
        };
    }
}
