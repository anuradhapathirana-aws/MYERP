<?php

declare(strict_types=1);

namespace App\Enums;

enum TaxType: string
{
    case Vat   = 'vat';
    case Sscl  = 'sscl';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Vat   => 'VAT',
            self::Sscl  => 'SSCL',
            self::Other => 'Other',
        };
    }
}
