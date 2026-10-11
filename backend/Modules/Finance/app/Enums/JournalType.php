<?php

declare(strict_types=1);

namespace Modules\Finance\Enums;

/**
 * Why a voucher exists. Reporting and filtering only -- it never changes how a
 * journal is validated or posted.
 *
 * The values mirror the string constants on App\Support\Journal\JournalRequest,
 * which core defines because core cannot reference this enum. Finance maps the
 * incoming string to a case here, so a typo on the Inventory side surfaces as
 * a clear failure rather than an unknown value in the column.
 */
enum JournalType: string
{
    /** Hand-entered by an accountant: depreciation, accruals, prepayments. */
    case General = 'general';

    /** The one-off journal that brings historical balances into the ledger. */
    case Opening = 'opening';

    /** A period-end correction, such as a closing-stock adjustment. */
    case Adjustment = 'adjustment';

    /** The mirror image of another journal. Never created by hand. */
    case Reversal = 'reversal';

    /** Written by a document confirming -- GRN, invoice, payment, receipt. */
    case Auto = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::General    => 'General',
            self::Opening    => 'Opening Balance',
            self::Adjustment => 'Adjustment',
            self::Reversal   => 'Reversal',
            self::Auto       => 'Automatic',
        };
    }

    /**
     * True for types a user may choose on the Journal Voucher screen.
     *
     * Auto belongs to the posting service and Reversal to the reverse action;
     * offering either in the form would let someone hand-write an entry that
     * claims to have come from a document.
     */
    public function isUserSelectable(): bool
    {
        return in_array($this, [self::General, self::Opening, self::Adjustment], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{value:string,label:string}> */
    public static function selectableOptions(): array
    {
        return array_values(array_map(fn (self $t): array => [
            'value' => $t->value,
            'label' => $t->label(),
        ], array_filter(self::cases(), fn (self $t): bool => $t->isUserSelectable())));
    }
}
