<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Contracts\JournalPoster;
use App\Services\SettingsService;
use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Enums\JournalStatus;
use Modules\Finance\Enums\JournalType;
use Modules\Finance\Models\JournalEntry;
use Modules\Finance\Models\LedgerAccount;

/**
 * Writes the general ledger. The Finance side of App\Contracts\JournalPoster.
 *
 * Bound over the core default by FinanceServiceProvider, so this is what runs
 * when the module is installed. Everything that moves money ends up here.
 *
 * ── Entitlement, not just installation ───────────────────────────────────────
 * A client can have the module files without having bought the module. The
 * first thing post() does is check module.finance, and when it is off this
 * behaves exactly like NullJournalPoster. Otherwise a client who never paid
 * for Finance would quietly accumulate journals they cannot see, since the
 * routes are gated separately.
 *
 * ── Review mode ──────────────────────────────────────────────────────────────
 * finance.posting_mode decides whether an automatic posting lands as draft or
 * posted. It defaults to review so the first month can be checked against what
 * finance would have entered by hand; once the mappings are trusted it is
 * flipped once and never touched again. It deliberately affects only AUTO
 * journals -- a reversal must always post, or voiding a document would leave
 * the original posting standing.
 */
final class JournalPostingService implements JournalPoster
{
    public const SETTING_POSTING_MODE = 'finance.posting_mode';
    public const MODE_REVIEW          = 'review';
    public const MODE_IMMEDIATE       = 'immediate';

    /**
     * Exact-balance tolerance, matching the 1c tolerance every decision in the
     * payment services already uses.
     */
    private const BALANCE_TOLERANCE = 0.01;

    /**
     * The most residue that may be written off to the rounding account. Beyond
     * a rupee it is a logic error in whoever built the request, not rounding,
     * and absorbing it silently would hide a real bug.
     */
    private const ROUNDING_TOLERANCE = 1.00;

    public function __construct(
        private readonly AccountResolver $accounts,
        private readonly SettingsService $settings,
    ) {}

    public function acceptsPostings(): bool
    {
        return $this->settings->isEnabled('module.finance');
    }

    public function post(JournalRequest $request): ?string
    {
        if (! $this->acceptsPostings()) {
            return null;
        }

        // A nested DB::transaction becomes a savepoint, so this composes
        // correctly inside the transaction confirm() already opened, and still
        // works for a standalone manual voucher.
        return DB::transaction(function () use ($request): string {
            $companyId = $request->companyId ?? $this->deriveCompanyId($request);

            $rows = $this->resolveLines($request, $companyId);
            $rows = $this->applyRounding($request, $rows, $companyId);

            $totalDebit  = round(array_sum(array_column($rows, 'debit')), 2);
            $totalCredit = round(array_sum(array_column($rows, 'credit')), 2);

            if (abs($totalDebit - $totalCredit) > self::BALANCE_TOLERANCE) {
                throw ValidationException::withMessages([
                    'lines' => sprintf(
                        'This journal does not balance. Debits total %s and credits total %s, a difference of %s.',
                        number_format($totalDebit, 2),
                        number_format($totalCredit, 2),
                        number_format($totalDebit - $totalCredit, 2),
                    ),
                ]);
            }

            $posts = $this->shouldPostImmediately($request);

            $entry = new JournalEntry([
                'journal_date'  => $request->journalDate,
                'document_date' => $request->documentDate,
                'journal_type'  => $this->journalType($request),
                'source_type'   => $request->sourceType,
                'source_id'     => $request->sourceId,
                'company_id'    => $companyId,
                'location_id'   => $request->locationId,
                'narration'     => $request->narration,
                'status'        => $posts ? JournalStatus::Posted : JournalStatus::Draft,
                'total_debit'   => $totalDebit,
                'total_credit'  => $totalCredit,
                'created_by'    => Auth::id(),
                'posted_by'     => $posts ? Auth::id() : null,
                'posted_at'     => $posts ? now() : null,
            ]);

            // Allocated under lockForUpdate inside this transaction, the same
            // contract as inv_purchase_requests.reference_no.
            $entry->journal_no = $this->nextJournalNo($request->journalDate);
            $entry->save();

            $entry->lines()->createMany($rows);

            return $entry->journal_no;
        });
    }

    public function reverseDocument(string $sourceType, int $sourceId, ?string $narration = null): ?string
    {
        if (! $this->settings->isEnabled('module.finance')) {
            return null;
        }

        return DB::transaction(function () use ($sourceType, $sourceId, $narration): ?string {
            $original = JournalEntry::forSource($sourceType, $sourceId)
                ->whereIn('status', [JournalStatus::Draft, JournalStatus::Posted])
                ->lockForUpdate()
                ->with('lines')
                ->first();

            // Nothing at all, or already reversed. Not an error: a document may
            // be voided twice by a double click.
            if ($original === null) {
                return null;
            }

            // A DRAFT never reached the books, so there is nothing to reverse --
            // posting it only to reverse it would put two entries in the ledger
            // for a transaction that never happened. Discard it instead.
            //
            // Leaving it was the bug: the draft stayed in the Journal Vouchers
            // list with a live Post button, so someone could post a receipt
            // that had already been voided. It also holds the unique
            // (source_type, source_id) slot, which would block the document
            // from ever posting again if it were somehow un-voided.
            if ($original->status === JournalStatus::Draft) {
                $this->discardDraft($original);

                return null;
            }

            return $this->reverse($original, $narration);
        });
    }

    /**
     * Remove a draft journal and its lines completely.
     *
     * A hard delete, deliberately. SoftDeletes exists on fin_journal_entries
     * for no good reason once a draft is gone: the lines are hard-deleted
     * either way (they have no life of their own), so a soft-deleted header is
     * an empty shell. Worse, that shell keeps occupying the unique
     * (source_type, source_id) slot, which silently prevents its own document
     * from ever posting again.
     *
     * The journal NUMBER is not reused, because nextJournalNo() reads through
     * DB::table() and so counts rows this method removed as well.
     *
     * Shared by reverseDocument() and JournalEntryService::delete() so the rule
     * lives in exactly one place.
     */
    public function discardDraft(JournalEntry $draft): void
    {
        if ($draft->status !== JournalStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => $draft->journal_no . ' is ' . $draft->status->label()
                    . ' and can never be deleted. Reverse it instead -- accounting '
                    . 'records are immutable once they are part of the books.',
            ]);
        }

        DB::transaction(function () use ($draft): void {
            $draft->lines()->delete();
            $draft->forceDelete();
        });
    }

    /**
     * Reverse one specific journal.
     *
     * Separate from reverseDocument() so the Journal list screen can reverse a
     * hand-entered voucher, which has no source document to look it up by.
     *
     * Note what this does NOT do: it never edits or deletes the original. The
     * original stays in the ledger and keeps counting towards balances -- see
     * JournalEntry::scopeInLedger(). The pair nets to zero only because both
     * are still there.
     */
    public function reverse(JournalEntry $original, ?string $narration = null): string
    {
        return DB::transaction(function () use ($original, $narration): string {
            if ($original->status !== JournalStatus::Posted) {
                throw ValidationException::withMessages([
                    'status' => 'Only a posted journal can be reversed. '
                        . $original->journal_no . ' is ' . $original->status->label() . '.',
                ]);
            }

            $original->loadMissing('lines');

            // Every line mirrored -- debit becomes credit and credit becomes
            // debit, keeping the same account, party and source. The original
            // is left untouched, because a posted entry is the audit trail.
            $mirror = $original->lines->values()->map(fn ($line, $i): array => [
                'ledger_account_id' => $line->ledger_account_id,
                'line_no'           => $i + 1,
                'debit'             => $line->credit,
                'credit'            => $line->debit,
                'description'       => $line->description,
                'company_id'        => $line->company_id,
                'location_id'       => $line->location_id,
                'party_type'        => $line->party_type,
                'party_id'          => $line->party_id,
                'source_type'       => $line->source_type,
                'source_id'         => $line->source_id,
            ])->all();

            $reversal = new JournalEntry([
                'journal_date'  => now()->toDateString(),
                'document_date' => $original->document_date,
                // source_type stays NULL so the unique index on
                // (source_type, source_id) cannot collide with the entry being
                // reversed. The link is reverses_journal_id.
                'source_type'   => null,
                'source_id'     => null,
                'journal_type'  => JournalType::Reversal,
                'company_id'    => $original->company_id,
                'location_id'   => $original->location_id,
                'narration'     => $narration ?? ('Reversal of ' . $original->journal_no . '.'),

                'status'              => JournalStatus::Posted,
                'total_debit'         => $original->total_credit,
                'total_credit'        => $original->total_debit,
                'reverses_journal_id' => $original->id,
                'created_by'          => Auth::id(),
                'posted_by'           => Auth::id(),
                'posted_at'           => now(),
            ]);

            $reversal->journal_no = $this->nextJournalNo(now());
            $reversal->save();
            $reversal->lines()->createMany($mirror);

            $original->update([
                'status'                 => JournalStatus::Reversed,
                'reversed_by_journal_id' => $reversal->id,
            ]);

            return $reversal->journal_no;
        });
    }

    public function hasPostingFor(string $sourceType, int $sourceId): bool
    {
        if (! $this->settings->isEnabled('module.finance')) {
            return false;
        }

        return JournalEntry::forSource($sourceType, $sourceId)
            ->whereIn('status', [JournalStatus::Draft, JournalStatus::Posted])
            ->exists();
    }

    public function postingMode(): string
    {
        $mode = $this->settings->get(self::SETTING_POSTING_MODE, self::MODE_REVIEW);

        return $mode === self::MODE_IMMEDIATE ? self::MODE_IMMEDIATE : self::MODE_REVIEW;
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Turn role keys into account ids and build the insertable rows.
     *
     * @return list<array<string, mixed>>
     */
    private function resolveLines(JournalRequest $request, ?int $companyId): array
    {
        $this->assertExplicitAccountsArePostable($request);

        $rows   = [];
        $lineNo = 1;

        foreach ($request->lines as $line) {
            $rows[] = [
                'ledger_account_id' => $line->ledgerAccountId ?? $this->resolveRole($line, $companyId),
                'line_no'           => $lineNo++,
                'debit'             => $line->debit,
                'credit'            => $line->credit,
                'description'       => $line->description,
                // The line is authoritative for reporting; the header value is
                // only the default, copied down so no report joins upward.
                'company_id'        => $line->companyId ?? $companyId,
                'location_id'       => $line->locationId ?? $request->locationId,
                'party_type'        => $line->partyType,
                'party_id'          => $line->partyId,
                'source_type'       => $line->sourceType,
                'source_id'         => $line->sourceId,
            ];
        }

        return $rows;
    }

    private function resolveRole(JournalLineRequest $line, ?int $companyId): int
    {
        $role = AccountRole::tryFrom((string) $line->role);

        if ($role === null) {
            throw ValidationException::withMessages([
                'lines' => 'Unknown posting role [' . $line->role . ']. A journal line must name a role '
                    . 'defined in AccountRole, or an explicit ledger account id.',
            ]);
        }

        return $this->accounts->resolve(
            $role,
            $companyId,
            $line->partyType,
            $line->partyId,
            // Only used if the installation keeps one account per party and
            // this one has none yet -- it names the account being created.
            $line->partyName,
        );
    }

    /**
     * Explicit account ids come from a user choosing a bank account on screen,
     * so they get the same existence and active checks the resolver applies to
     * mapped accounts.
     */
    private function assertExplicitAccountsArePostable(JournalRequest $request): void
    {
        $ids = $this->explicitAccountIds($request);

        if ($ids === []) {
            return;
        }

        $accounts = LedgerAccount::whereIn('id', $ids)
            ->get(['id', 'code', 'ledger_account_name', 'is_active']);

        if ($accounts->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'lines' => 'A journal line names a ledger account that does not exist.',
            ]);
        }

        $inactive = $accounts->firstWhere('is_active', false);

        if ($inactive !== null) {
            throw ValidationException::withMessages([
                'lines' => 'Ledger account ' . $inactive->code . ' (' . $inactive->ledger_account_name
                    . ') is inactive and cannot be posted to.',
            ]);
        }
    }

    /**
     * Absorb sub-rupee residue into the rounding account.
     *
     * Amounts are stored at four decimals but every decision in the payment
     * services rounds to cents, so an automatic posting can land a cent or two
     * out through no fault of its own. A hand-entered voucher gets no such
     * licence: the form already blocks saving until the difference reads zero,
     * so residue there means the user got it wrong and must see that.
     *
     * @param  list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function applyRounding(JournalRequest $request, array $rows, ?int $companyId): array
    {
        $imbalance = round(
            array_sum(array_column($rows, 'debit')) - array_sum(array_column($rows, 'credit')),
            2,
        );

        // Already square, or within the cent the rest of the system tolerates --
        // a rounding line would be noise on the voucher for no gain.
        if (abs($imbalance) <= self::BALANCE_TOLERANCE) {
            return $rows;
        }

        // A hand-entered voucher must be balanced by its author, and anything
        // over a rupee is a bug rather than rounding. Both fall through to the
        // balance check in post(), which reports the difference.
        if ($this->journalType($request)->isUserSelectable() || abs($imbalance) > self::ROUNDING_TOLERANCE) {
            return $rows;
        }

        $rows[] = [
            'ledger_account_id' => $this->accounts->resolve(AccountRole::RoundingDifference, $companyId),
            'line_no'           => count($rows) + 1,
            // A debit surplus is corrected by a credit, and vice versa.
            'debit'             => $imbalance < 0 ? abs($imbalance) : 0,
            'credit'            => $imbalance > 0 ? $imbalance : 0,
            'description'       => 'Rounding difference',
            'company_id'        => $companyId,
            'location_id'       => $request->locationId,
            'party_type'        => null,
            'party_id'          => null,
            'source_type'       => null,
            'source_id'         => null,
        ];

        return $rows;
    }

    /**
     * Which company this journal belongs to, when the caller did not say.
     *
     * Taken from the cash or bank accounts named explicitly on the lines, which
     * already carry a company. This is why Inventory needs no company_id column
     * on its payment and receipt headers, and never has to read
     * fin_ledger_accounts to work one out.
     */
    private function deriveCompanyId(JournalRequest $request): ?int
    {
        $ids = $this->explicitAccountIds($request);

        if ($ids === []) {
            return null;
        }

        $companies = LedgerAccount::whereIn('id', $ids)
            ->whereNotNull('company_id')
            ->distinct()
            ->pluck('company_id')
            ->map(fn ($c): int => (int) $c)
            ->all();

        if (count($companies) > 1) {
            throw ValidationException::withMessages([
                'lines' => 'This document draws on bank or cash accounts belonging to more than one '
                    . 'company. Split it into one document per company.',
            ]);
        }

        return $companies[0] ?? null;
    }

    /** @return list<int> */
    private function explicitAccountIds(JournalRequest $request): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (JournalLineRequest $l): ?int => $l->ledgerAccountId, $request->lines),
        )));
    }

    private function journalType(JournalRequest $request): JournalType
    {
        return JournalType::tryFrom($request->journalType) ?? JournalType::Auto;
    }

    /**
     * Review mode holds automatic postings as drafts. It never holds a
     * reversal: leaving one unposted would mean a voided document still had a
     * live journal standing against it.
     */
    private function shouldPostImmediately(JournalRequest $request): bool
    {
        if ($this->journalType($request) !== JournalType::Auto) {
            return true;
        }

        return $this->postingMode() !== self::MODE_REVIEW;
    }

    /**
     * Non-binding preview of the next number, for the form header.
     *
     * Lock-free and never persisted, the same split AccountCodeGenerator uses
     * for its preview endpoints. Two people opening the form at once will both
     * see the same number; whoever posts first gets it.
     */
    public function previewNextJournalNo(?DateTimeInterface $date = null): string
    {
        return $this->nextJournalNo($date ?? now(), lock: false);
    }

    /**
     * JV-2026-0001, sequenced within the posting year.
     *
     * DB::table bypasses the SoftDeletes scope on purpose: a number issued to a
     * draft someone later deleted is still a spent number. An identifier that
     * may have appeared on a printed voucher must never be reissued.
     */
    private function nextJournalNo(DateTimeInterface $date, bool $lock = true): string
    {
        $prefix = 'JV-' . $date->format('Y') . '-';

        $query = DB::table('fin_journal_entries')
            ->where('journal_no', 'like', $prefix . '%');

        if ($lock) {
            $query->lockForUpdate();
        }

        $highest = $query->max('journal_no');

        $sequence = $highest === null
            ? 1
            : ((int) substr((string) $highest, strlen($prefix))) + 1;

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
