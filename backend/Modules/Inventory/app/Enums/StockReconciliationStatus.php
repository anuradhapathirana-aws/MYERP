<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum StockReconciliationStatus: string
{
    case Draft            = 'draft';
    case PendingApproval  = 'pending_approval';
    case Approved         = 'approved';
    case Rejected         = 'rejected';

    public function label(): string
    {
        return match($this) {
            self::Draft           => 'Draft',
            self::PendingApproval => 'Pending Approval',
            self::Approved        => 'Approved',
            self::Rejected        => 'Rejected',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match($this) {
            self::Draft           => $next === self::PendingApproval,
            self::PendingApproval => in_array($next, [self::Approved, self::Rejected], true),
            default               => false,
        };
    }
}
