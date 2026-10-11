<?php

declare(strict_types=1);

namespace App\Support\Journal;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * A complete posting request: one voucher and all of its lines.
 *
 * Built by whoever owns the document (Inventory for a GRN or a payment,
 * Finance for a journal voucher) and handed to App\Contracts\JournalPoster.
 * Lives in core so Inventory can build one without referencing Finance.
 *
 * ── Identity and idempotency ─────────────────────────────────────────────────
 * sourceType + sourceId identify the originating document. fin_journal_entries
 * carries a UNIQUE index on that pair, so a document can never post twice even
 * if confirm() is somehow called again. A manual voucher passes
 * SOURCE_MANUAL with a null id, and MySQL/SQLite both allow unlimited NULLs in
 * a unique index, so manual vouchers are unconstrained.
 *
 * ── Why journalType is a string constant, not an enum ────────────────────────
 * The enum would have to live in Finance, and core cannot depend on Finance.
 * Constants give the same call-site safety without the dependency.
 */
final class JournalRequest
{
    public const TYPE_GENERAL    = 'general';
    public const TYPE_OPENING    = 'opening';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_REVERSAL   = 'reversal';
    public const TYPE_AUTO       = 'auto';

    /** Passed as sourceType by a hand-entered voucher, which has no document. */
    public const SOURCE_MANUAL = null;

    /** @param list<JournalLineRequest> $lines */
    public function __construct(
        public readonly ?string           $sourceType,
        public readonly ?int              $sourceId,
        public readonly DateTimeInterface $journalDate,
        public readonly array             $lines,
        public readonly string            $journalType   = self::TYPE_AUTO,
        public readonly ?DateTimeInterface $documentDate = null,
        public readonly ?int              $companyId     = null,
        public readonly ?int              $locationId    = null,
        public readonly ?string           $narration     = null,
    ) {
        if ($lines === []) {
            throw new InvalidArgumentException('A journal needs at least one line.');
        }

        foreach ($lines as $line) {
            if (! $line instanceof JournalLineRequest) {
                throw new InvalidArgumentException('Every journal line must be a JournalLineRequest.');
            }
        }

        // A one-sided journal is not a journal. Checked here as well as in the
        // posting service so a caller building the request wrongly fails at the
        // point of the mistake rather than deep inside Finance.
        if (count($lines) < 2) {
            throw new InvalidArgumentException(
                'A journal needs at least two lines — one debit and one credit.',
            );
        }
    }

    public function totalDebit(): float
    {
        return round(array_sum(array_map(fn (JournalLineRequest $l): float => $l->debit, $this->lines)), 2);
    }

    public function totalCredit(): float
    {
        return round(array_sum(array_map(fn (JournalLineRequest $l): float => $l->credit, $this->lines)), 2);
    }

    /**
     * Difference between the two sides, positive when debits exceed credits.
     *
     * Not an equality check: callers compare it against a tolerance, because
     * the source amounts are stored at 4 decimals and decided at 2.
     */
    public function imbalance(): float
    {
        return round($this->totalDebit() - $this->totalCredit(), 2);
    }

    public function isBalanced(float $tolerance = 0.01): bool
    {
        return abs($this->imbalance()) <= $tolerance;
    }
}
