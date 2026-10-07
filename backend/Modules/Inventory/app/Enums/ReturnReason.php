<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum ReturnReason: string
{
    case Damaged      = 'damaged';
    case WrongItem    = 'wrong_item';
    case Excess       = 'excess';
    case QualityIssue = 'quality_issue';
    case Other        = 'other';

    public function label(): string
    {
        return match($this) {
            self::Damaged      => 'Damaged',
            self::WrongItem    => 'Wrong Item',
            self::Excess       => 'Excess',
            self::QualityIssue => 'Quality Issue',
            self::Other        => 'Other',
        };
    }
}
