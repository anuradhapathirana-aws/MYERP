<?php

declare(strict_types=1);

namespace Modules\Finance\DTOs;

use Modules\Finance\Enums\JournalType;
use Modules\Finance\Http\Requests\JournalEntryRequest;

/**
 * A hand-entered voucher, on its way to the ledger.
 *
 * Lines stay as plain arrays, matching SupplierPaymentData and the other
 * line-bearing DTOs in this codebase rather than introducing a second
 * convention.
 *
 * Note what is absent: no status, and no journal number. The service decides
 * the first and the generator allocates the second, so neither can arrive from
 * user input.
 */
final class JournalEntryData
{
    /** @param list<array<string, mixed>> $lines */
    public function __construct(
        public readonly string      $journal_date,
        public readonly ?string     $document_date,
        public readonly JournalType $journal_type,
        public readonly ?int        $location_id,
        public readonly ?string     $narration,
        public readonly array       $lines,
    ) {}

    public static function fromRequest(JournalEntryRequest $request): self
    {
        $narration = $request->input('narration');
        $narration = is_string($narration) ? trim($narration) : null;

        $lines = [];

        foreach ((array) $request->input('lines', []) as $row) {
            $debit  = round((float) ($row['debit'] ?? 0), 2);
            $credit = round((float) ($row['credit'] ?? 0), 2);

            // The grid always renders a fixed number of rows, so blank ones
            // arrive on every save. Dropping them here keeps the "at least two
            // lines" rule honest -- it must count real lines, not placeholders.
            if ($debit <= 0 && $credit <= 0) {
                continue;
            }

            $description = $row['description'] ?? null;
            $description = is_string($description) ? trim($description) : null;

            $partyType = $row['party_type'] ?? null;
            $partyType = is_string($partyType) && $partyType !== '' ? $partyType : null;

            // A party id without a party type is meaningless, and a zero is the
            // empty dropdown rather than a real id.
            $partyId = null;

            if ($partyType !== null) {
                $candidate = (int) ($row['party_id'] ?? 0);
                $partyId   = $candidate > 0 ? $candidate : null;
            }

            $lines[] = [
                'ledger_account_id' => (int) $row['ledger_account_id'],
                'debit'             => $debit,
                'credit'            => $credit,
                'description'       => $description === '' ? null : $description,
                'party_type'        => $partyId === null ? null : $partyType,
                'party_id'          => $partyId,
            ];
        }

        $documentDate = $request->input('document_date');

        return new self(
            journal_date:  (string) $request->input('journal_date'),
            document_date: is_string($documentDate) && $documentDate !== '' ? $documentDate : null,
            journal_type:  JournalType::from((string) $request->input('journal_type', JournalType::General->value)),
            location_id:   $request->filled('location_id') ? (int) $request->input('location_id') : null,
            narration:     $narration === '' ? null : $narration,
            lines:         $lines,
        );
    }
}
