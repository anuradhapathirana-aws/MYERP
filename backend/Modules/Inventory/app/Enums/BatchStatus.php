<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum BatchStatus: string
{
    case Active     = 'active';
    case Quarantine = 'quarantine';
    case OnHold     = 'on_hold';
    case Recalled   = 'recalled';
    case Expired    = 'expired';
    case Exhausted  = 'exhausted';

    public function label(): string
    {
        return match ($this) {
            self::Active     => 'Active',
            self::Quarantine => 'Quarantine',
            self::OnHold     => 'On Hold',
            self::Recalled   => 'Recalled',
            self::Expired    => 'Expired',
            self::Exhausted  => 'Exhausted',
        };
    }

    public function canDispatch(): bool
    {
        return $this === self::Active;
    }

    public function color(): string
    {
        return match ($this) {
            self::Active     => 'green',
            self::Quarantine => 'amber',
            self::OnHold     => 'slate',
            self::Recalled   => 'red',
            self::Expired    => 'red',
            self::Exhausted  => 'slate',
        };
    }
}
