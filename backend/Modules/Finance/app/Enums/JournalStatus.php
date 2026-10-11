<?php

declare(strict_types=1);

namespace Modules\Finance\Enums;

/**
 * The life of a voucher. There are only three states, and only one transition
 * out of each.
 *
 *   draft ---post---> posted ---reverse---> reversed
 *
 * There is deliberately no path back from posted. A posted journal is part of
 * the audit trail: it is never edited, never deleted, not even soft-deleted.
 * A correction posts a mirror-image journal and the two link through
 * reverses_journal_id, so the record of the mistake survives alongside its fix.
 *
 * Draft exists for two reasons: a hand-entered voucher being built up, and the
 * Review posting mode, where a confirmed document creates a draft for finance
 * to check before it becomes part of the books.
 */
enum JournalStatus: string
{
    case Draft    = 'draft';
    case Posted   = 'posted';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Draft    => 'Draft',
            self::Posted   => 'Posted',
            self::Reversed => 'Reversed',
        };
    }

    /** True once the voucher counts towards any balance or report. */
    public function isPosted(): bool
    {
        return $this === self::Posted;
    }

    /** Only a draft may be changed or removed. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<array{value:string,label:string}> */
    public static function options(): array
    {
        return array_map(fn (self $s): array => [
            'value' => $s->value,
            'label' => $s->label(),
        ], self::cases());
    }
}
