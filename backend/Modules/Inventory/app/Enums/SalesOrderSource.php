<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum SalesOrderSource: string
{
    case Phone      = 'phone';
    case WhatsApp   = 'whatsapp';
    case WalkIn     = 'walk_in';
    case FieldVisit = 'field_visit';

    public function label(): string
    {
        return match($this) {
            self::Phone      => 'Phone',
            self::WhatsApp   => 'WhatsApp',
            self::WalkIn     => 'Walk-in',
            self::FieldVisit => 'Field Visit',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
