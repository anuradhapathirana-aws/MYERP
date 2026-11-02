<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a payment mode IS, as opposed to what the client calls it.
 *
 * The name and code on a payment mode are free text ("CEFT", "SLIPS"), so the
 * rules -- which account it pays from, whether it needs a cheque number --
 * hang on this fixed type instead. A client can add any mode and it behaves
 * correctly without a code change.
 *
 * Inventory still reads the legacy code; only Finance reads the type for now.
 */
enum PaymentModeType: string
{
    case Cash         = 'cash';
    case Cheque       = 'cheque';
    case BankTransfer = 'bank_transfer';
    case Card         = 'card';
    case Setoff       = 'setoff';

    public function label(): string
    {
        return match ($this) {
            self::Cash         => 'Cash',
            self::Cheque       => 'Cheque',
            self::BankTransfer => 'Bank Transfer',
            self::Card         => 'Card',
            self::Setoff       => 'Set-off',
        };
    }

    /**
     * The type an existing mode gets from its code. Anything unrecognised is
     * treated as a bank transfer: non-cash, so it must come from a bank.
     */
    public static function fromLegacyCode(?string $code): self
    {
        return match (strtolower(trim((string) $code))) {
            'cash', 'petty_cash' => self::Cash,
            'cheque'             => self::Cheque,
            'card'               => self::Card,
            'setoff'             => self::Setoff,
            default              => self::BankTransfer,
        };
    }

    /** @return list<array{value:string, label:string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $t): array => ['value' => $t->value, 'label' => $t->label()],
            self::cases(),
        );
    }
}
