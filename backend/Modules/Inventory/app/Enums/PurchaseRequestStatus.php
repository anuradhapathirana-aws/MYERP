<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum PurchaseRequestStatus: string
{
    case Draft     = 'draft';
    case Submitted = 'submitted';
    case Approved  = 'approved';
    case Rejected  = 'rejected';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function label(): string
    {
        return match($this) {
            self::Draft     => 'Draft',
            self::Submitted => 'Pending Approval',
            self::Approved  => 'Approved',
            self::Rejected  => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match($this) {
            self::Draft     => in_array($next, [self::Submitted, self::Cancelled]),
            self::Submitted => in_array($next, [self::Approved, self::Rejected, self::Cancelled]),
            self::Approved  => in_array($next, [self::Completed, self::Cancelled]),
            default         => false,
        };
    }
}
