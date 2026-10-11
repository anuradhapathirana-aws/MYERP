<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

/**
 * The life of a customer receipt.
 *
 *   draft ---confirm---> confirmed ---void---> voided
 *
 * Voided is the only way back from Confirmed, and it is not an undo: the
 * document stays exactly as it was, the general ledger receives a reversing
 * journal, and the invoices it settled become outstanding again. Deleting a confirmed receipt
 * would erase the record that the mistake ever happened, which is the one
 * thing an audit trail exists to prevent -- so delete() still refuses
 * anything past Draft.
 */
enum CustomerReceiptStatus: string
{
    case Draft     = 'draft';
    case Confirmed = 'confirmed';
    case Voided    = 'voided';

    public function label(): string
    {
        return match($this) {
            self::Draft     => 'Draft',
            self::Confirmed => 'Confirmed',
            self::Voided    => 'Voided',
        };
    }

    /** Only a confirmed receipt counts towards an outstanding balance. */
    public function isLive(): bool
    {
        return $this === self::Confirmed;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
