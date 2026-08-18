<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum SalesChannelType: string
{
    case Wholesale = 'Wholesale';
    case Ecommerce = 'e-commerce';
    case Retail    = 'Retail';

    public function label(): string
    {
        return match ($this) {
            self::Wholesale => 'Wholesale',
            self::Ecommerce => 'e-commerce',
            self::Retail    => 'Retail',
        };
    }
}
