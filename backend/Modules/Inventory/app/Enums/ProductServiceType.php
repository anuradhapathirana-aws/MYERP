<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum ProductServiceType: string
{
    case Product = 'product';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Product',
            self::Service => 'Service',
        };
    }
}
