<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use DateTimeImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Finance\DTOs\JournalEntryData;
use Modules\Finance\Enums\JournalStatus;
use Modules\Finance\Models\JournalEntry;

/**
 * The hand-entered voucher, and the list it appears in.
 *
 * This is the accountant's escape hatch -- depreciation, accruals,
 * prepayments, corrections, year-end. Everything a document cannot produce.
 * Without it the ledger is incomplete no matter how much is automated, because
 * no document in this system will ever generate a depreciation entry.
 *
 * All writing goes through JournalPostingService, which owns balance checking,
 * account resolution and number allocation. This class only shapes form input
 * into a JournalRequest and guards the list actions.
 */
class JournalEntryService
{
    private const EAGER = ['lines.ledgerAccount', 'company', 'location'];

    public function __construct(private readonly JournalPostingService $posting) {}

    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = JournalEntry::with(['company', 'location'])
            ->withCount('lines')
            // Newest first: the list is used to check what was just posted far
            // more often than to browse history.
            ->orderByDesc('journal_date')
            ->orderByDesc('id');

        if (! empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(fn ($q) => $q->where('journal_no', 'like', $term)->orWhere('narration', 'like', $term));
        }

        if (! empty($filters['journal_type'])) {
            $query->where('journal_type', $filters['journal_type']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        if (! empty($filters['location_id'])) {
            $query->where('location_id', (int) $filters['location_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('journal_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('journal_date', '<=', $filters['date_to']);
        }

        // A source filter answers "show me what this document posted".
        if (! empty($filters['source_type'])) {
            $query->where('source_type', $filters['source_type']);
        }

        return $query->paginate($perPage);
    }

    public function find(JournalEntry $entry): JournalEntry
    {
        return $entry->load(self::EAGER);
    }

    /**
     * Post a voucher typed by a person.
     *
     * The lines carry explicit account ids rather than role keys: on this
     * screen the user picks every account themselves, which is exactly what
     * makes it the escape hatch. Roles belong to automatic postings, where
     * there is one correct answer and no user should be guessing at it.
     */
    public function post(JournalEntryData $data): JournalEntry
    {
        $lines = [];

        foreach ($data->lines as $row) {
            $lines[] = $row['debit'] > 0
                ? JournalLineRequest::debit(
                    $row['ledger_account_id'],
                    $row['debit'],
                    description: $row['description'],
                    partyType:   $row['party_type'],
                    partyId:     $row['party_id'],
                )
                : JournalLineRequest::credit(
                    $row['ledger_account_id'],
                    $row['credit'],
                    description: $row['description'],
                    partyType:   $row['party_type'],
                    partyId:     $row['party_id'],
                );
        }

        $journalNo = $this->posting->post(new JournalRequest(
            sourceType:   JournalRequest::SOURCE_MANUAL,
            sourceId:     null,
            journalDate:  new DateTimeImmutable($data->journal_date),
            lines:        $lines,
            journalType:  $data->journal_type->value,
            documentDate: $data->document_date === null ? null : new DateTimeImmutable($data->document_date),
            locationId:   $data->location_id,
            narration:    $data->narration,
        ));

        // Only reachable if the module were disabled, which the route gate
        // already prevents. Guarded anyway so a silent no-op can never be
        // mistaken for a successful post.
        if ($journalNo === null) {
            throw ValidationException::withMessages([
                'journal' => 'The Finance module is not enabled, so nothing was posted.',
            ]);
        }

        return JournalEntry::with(self::EAGER)->where('journal_no', $journalNo)->firstOrFail();
    }

    /**
     * Promote a draft to posted.
     *
     * The other half of review mode: an automatic posting lands as a draft,
     * finance checks it against what they would have entered by hand, then
     * posts it from the list.
     */
    public function postDraft(JournalEntry $entry): JournalEntry
    {
        if ($entry->status !== JournalStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => $entry->journal_no . ' is already ' . $entry->status->label()
                    . ' and cannot be posted again.',
            ]);
        }

        if (! $entry->isBalanced()) {
            throw ValidationException::withMessages([
                'status' => $entry->journal_no . ' does not balance and cannot be posted.',
            ]);
        }

        $entry->update([
            'status'    => JournalStatus::Posted,
            'posted_by' => Auth::id(),
            'posted_at' => now(),
        ]);

        return $entry->fresh()->load(self::EAGER);
    }

    public function reverse(JournalEntry $entry, ?string $narration = null): JournalEntry
    {
        $reversalNo = $this->posting->reverse($entry, $narration);

        return JournalEntry::with(self::EAGER)->where('journal_no', $reversalNo)->firstOrFail();
    }

    /**
     * Delete a draft. Never a posted journal.
     *
     * This is the guard the design rule calls for: leaving deletion unguarded
     * would invite the one action that destroys an audit trail. A posted
     * mistake is corrected by a reversal, which keeps the record of the mistake
     * beside its fix.
     *
     * Delegates so the rule, and the hard-vs-soft decision behind it, lives in
     * one place -- discardDraft() explains why it is a hard delete.
     */
    public function delete(JournalEntry $entry): void
    {
        $this->posting->discardDraft($entry);
    }
}
