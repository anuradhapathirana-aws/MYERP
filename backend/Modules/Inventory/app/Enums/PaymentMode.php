<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum PaymentMode: string
{
    case Cash         = 'cash';
    case Cheque       = 'cheque';
    case BankTransfer = 'bank_transfer';
    case Credit       = 'credit';

    public function label(): string
    {
        return match($this) {
            self::Cash         => 'Cash',
            self::Cheque       => 'Cheque',
            self::BankTransfer => 'Bank Transfer',
            self::Credit       => 'Credit',
        };
    }

    /** @return array<int, array{value:string, label:string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $mode): array => ['value' => $mode->value, 'label' => $mode->label()],
            self::cases(),
        );
    }
}
