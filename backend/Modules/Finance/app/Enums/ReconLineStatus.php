<?php

declare(strict_types=1);

namespace Modules\Finance\Enums;

/**
 * What the bank statement says about one bank line of the ledger.
 *
 * Realized and Returned both mean "the bank has seen it" and clear the line;
 * a returned cheque also gets a bounce entry that puts the money back.
 * Not Presented means it is not on this statement: it stays uncleared and
 * shows again on the next reconciliation.
 *
 * Returned Later is a cheque an EARLIER posted reconciliation realized, which
 * the bank debited (or credited) back on this statement. The cheque line stays
 * cleared where it was; this statement shows the money going back, so the
 * line counts against the closing balance in the opposite direction, and its
 * bounce entry is posted on the return date.
 */
enum ReconLineStatus: string
{
    case Realized      = 'realized';
    case Returned      = 'returned';
    case NotPresented  = 'not_presented';
    case ReturnedLater = 'returned_later';

    public function label(): string
    {
        return match ($this) {
            self::Realized      => 'Realized',
            self::Returned      => 'Returned',
            self::NotPresented  => 'Not Presented',
            self::ReturnedLater => 'Returned after clearing',
        };
    }

    /** Clears the line it names. A late return names a line already cleared. */
    public function clears(): bool
    {
        return $this === self::Realized || $this === self::Returned;
    }

    /** Posts a bounce entry when the reconciliation is posted. */
    public function bounces(): bool
    {
        return $this === self::Returned || $this === self::ReturnedLater;
    }

    /** @return list<string> */
    public static function clearing(): array
    {
        return [self::Realized->value, self::Returned->value];
    }
}
