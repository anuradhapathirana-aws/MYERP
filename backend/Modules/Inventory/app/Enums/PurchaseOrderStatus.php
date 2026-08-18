<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum PurchaseOrderStatus: string
{
    case Draft             = 'draft';
    case Sent              = 'sent';
    case Confirmed         = 'confirmed';
    case PartiallyReceived = 'partially_received';
    case Completed         = 'completed';
    case Cancelled         = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::Draft             => 'Draft',
            self::Sent              => 'Sent to Supplier',
            self::Confirmed         => 'Confirmed',
            self::PartiallyReceived => 'Partially Received',
            self::Completed         => 'Completed',
            self::Cancelled         => 'Cancelled',
        };
    }

    public function canReceiveGoods(): bool
    {
        return in_array($this, [self::Confirmed, self::PartiallyReceived]);
    }
}
