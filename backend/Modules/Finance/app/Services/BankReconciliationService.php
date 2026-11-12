<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Contracts\ChequeReturnHandler;
use App\Contracts\JournalPoster;
use App\Enums\PaymentModeType;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CompanyAccessService;
use App\Support\Banking\ChequeReturnRequest;
use App\Support\Journal\JournalLineRequest;
use App\Support\Journal\JournalRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\DTOs\BankReconciliationData;
use Modules\Finance\Enums\AccountRole;
use Modules\Finance\Enums\BankReconciliationStatus;
use Modules\Finance\Enums\JournalStatus;
use Modules\Finance\Enums\ReconLineStatus;
use Modules\Finance\Models\BankReconciliation;
use Modules\Finance\Models\BankReconciliationLine;
use Modules\Finance\Models\Bill;
use Modules\Finance\Models\LedgerAccount;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Bank reconciliation: match a bank account's ledger lines to its statement.
 *
 * The book side is the ledger itself -- every line on the bank account, from
 * any document or a manual voucher. A line is "uncleared" until a POSTED
 * reconciliation marks it realized or returned; uncleared lines carry forward
 * to the next statement on their own, which is what makes unpresented cheques
 * and deposits in transit show up month after month without anyone re-keying
 * them.
 *
 *   closing  = opening + realized deposits - realized withdrawals
 *              - deposits returned after clearing + withdrawals returned after clearing
 *   variance = statement balance - closing          (0.00 to post)
 *
 * A cheque returned on the same statement counts as neither: the statement
 * shows it going in and coming back, and its bounce entry (posted by the
 * document that wrote it, through ChequeReturnHandler) does the same in the
 * books. A cheque realized on an EARLIER statement and returned on this one
 * (Returned Later) moves this statement's balance the other way. Either way
 * the bounce entry's own bank line is cleared on this reconciliation.
 *
 * The first reconciliation of an account has a start date (date_from): lines
 * dated before it are taken as already cleared -- the typed opening balance
 * stands for them -- so an opening-balance journal is never counted twice.
 * They stay findable through Add Transaction for an old cheque that is
 * presented later.
 *
 * Voided documents: reversing a journal leaves the original and its mirror both
 * on the bank account. While neither has cleared they cancel out, so the pair
 * is hidden rather than listed as an unpresented cheque and a phantom deposit.
 *
 * Lock order, everywhere: the bank account row, then the reconciliation row.
 * Every write for one account therefore queues behind the account lock, which
 * is what makes "one draft per account", "post in statement order" and "a line
 * clears once" safe without unique indexes.
 */
class BankReconciliationService
{
    private const EAGER = [
        'company:id,company_name',
        'bankAccount:id,code,ledger_account_name,bank_account_no,bank_id,bank_branch_id,company_id',
        'bankAccount.bank:id,bank_name',
        'bankAccount.bankBranch:id,branch_name',
    ];

    private const SORTABLE = ['recon_no', 'statement_date', 'statement_balance', 'created_at'];

    public const MAX_PER_PAGE = 100;

    /** Uncleared lines one screen may hold. Past this the user narrows the date. */
    public const MAX_WORKSPACE_LINES = 3000;

    /** Posting needs an exact match, to the cent. */
    private const TOLERANCE_CENTS = 0;

    /** The bank-only entries "New Bank Entry" may post, and which way the money goes. */
    public const ENTRY_TYPES = [
        'bank_charges' => ['role' => AccountRole::BankCharges, 'direction' => BankReconciliationLine::WITHDRAWAL, 'label' => 'Bank Charges'],
        'interest'     => ['role' => AccountRole::InterestIncome, 'direction' => BankReconciliationLine::DEPOSIT, 'label' => 'Interest Income'],
    ];

    private const LINE_COLUMNS = [
        'l.id', 'l.journal_entry_id', 'l.line_no', 'l.debit', 'l.credit', 'l.description',
        'l.source_type', 'l.source_id', 'l.instrument_type', 'l.instrument_no', 'l.instrument_date',
        'e.journal_no', 'e.journal_date', 'e.narration', 'e.journal_type',
        'e.reverses_journal_id', 'e.reversed_by_journal_id',
    ];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly FinanceControlService $controls,
        private readonly CompanyAccessService  $companyAccess,
        private readonly AuditLogger           $audit,
        private readonly AccountResolver       $resolver,
    ) {}

    // ── Reading ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $query = BankReconciliation::query()->with(self::EAGER);

        $this->companyAccess->scope($query, $this->actor(), 'company_id', includeNull: false);

        if (! empty($filters['search'])) {
            $term = '%' . addcslashes((string) $filters['search'], '%_\\') . '%';
            $query->where(fn ($q) => $q->where('recon_no', 'like', $term)->orWhere('notes', 'like', $term));
        }

        if (! empty($filters['status']) && BankReconciliationStatus::tryFrom((string) $filters['status']) !== null) {
            $query->where('status', $filters['status']);
        }

        foreach (['company_id', 'bank_account_id'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, (int) $filters[$key]);
            }
        }

        if (! empty($filters['date_from']) && $this->isDate($filters['date_from'])) {
            $query->whereDate('statement_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to']) && $this->isDate($filters['date_to'])) {
            $query->whereDate('statement_date', '<=', $filters['date_to']);
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true) ? $filters['sort'] : 'statement_date';
        $dir  = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $dir)->orderByDesc('id')->paginate($perPage);
    }

    public function find(BankReconciliation $recon): BankReconciliation
    {
        $this->assertAccessible($recon);

        return $recon->load([...self::EAGER, 'lines', 'creator:id,name', 'poster:id,name']);
    }

    /** @return EloquentCollection<int, AuditLog> */
    public function history(BankReconciliation $recon): EloquentCollection
    {
        $this->assertAccessible($recon);

        return AuditLog::with('user:id,name')
            ->where('auditable_type', BankReconciliation::AUDIT_TYPE)
            ->where('auditable_id', $recon->id)
            ->orderByDesc('id')
            ->get();
    }

    public function previewNextNo(?string $date = null): string
    {
        return $this->numbers->preview(
            DocumentNumberService::TYPE_BANK_RECONCILIATION,
            $date !== null && $this->isDate($date) ? CarbonImmutable::parse($date) : CarbonImmutable::today(),
        );
    }

    /**
     * Everything the matching screen needs for one account and statement date:
     * the opening balance (fixed by the last posted reconciliation, typed on the
     * first), the book balance, and every uncleared bank line from the start
     * date up to the statement date -- plus, when a draft is being edited, every
     * line it already holds, wherever it sits (even past the 3,000-line cap).
     *
     * @param  ?string $dateFrom the start date typed on a first reconciliation
     * @return array<string, mixed>
     */
    public function workspace(int $bankAccountId, string $statementDate, ?int $reconId = null, ?string $dateFrom = null): array
    {
        $account = $this->accessibleBank($bankAccountId);
        $last    = $this->lastPosted($account->id);
        $cutoff  = $last === null
            ? ($dateFrom !== null && $this->isDate($dateFrom) ? $dateFrom : null)
            : $this->accountCutoff($account->id);

        $heldOpen = [];
        $heldLate = [];

        if ($reconId !== null) {
            $recon = BankReconciliation::findOrFail($reconId);
            $this->assertAccessible($recon);

            foreach ($recon->lines()->get(['journal_line_id', 'status']) as $line) {
                if ($line->status === ReconLineStatus::ReturnedLater) {
                    $heldLate[] = (int) $line->journal_line_id;
                } else {
                    $heldOpen[] = (int) $line->journal_line_id;
                }
            }
        }

        $rows = $this->unclearedQuery($account->id)
            ->whereDate('e.journal_date', '<=', $statementDate)
            ->when($cutoff !== null, fn ($q) => $q->whereDate('e.journal_date', '>=', $cutoff))
            ->when($heldOpen !== [], fn ($q) => $q->whereNotIn('l.id', $heldOpen))
            ->orderBy('e.journal_date')
            ->orderBy('l.id')
            ->limit(self::MAX_WORKSPACE_LINES + 1)
            ->get();

        $truncated = $rows->count() > self::MAX_WORKSPACE_LINES;
        $rows      = $rows->take(self::MAX_WORKSPACE_LINES);

        // A draft's own lines never fall off the end of the list: its marks
        // would be lost on the next save.
        if ($heldOpen !== []) {
            $rows = $rows->concat($this->unclearedQuery($account->id)->whereIn('l.id', $heldOpen)->get())
                ->sortBy([['journal_date', 'asc'], ['id', 'asc']])
                ->values();
        }

        $late = $heldLate === []
            ? collect()
            : $this->realizedChequeQuery($account->id)->whereIn('l.id', $heldLate)->get();

        return [
            'bank_account_id'     => $account->id,
            'statement_date'      => $statementDate,
            'is_first'            => $last === null,
            'cutoff_date'         => $cutoff,
            'opening_balance'     => $last === null ? null : (string) $last->statement_balance,
            'last_recon_no'       => $last?->recon_no,
            'last_statement_date' => $last?->statement_date?->toDateString(),
            'suggested_date_from' => $last?->statement_date?->addDay()->toDateString(),
            'book_balance'        => Bill::fromCents($this->bookBalanceCents($account->id, $statementDate)),
            'truncated'           => $truncated,
            'lines'               => [...$this->present($this->withoutCancelledPairs($rows)), ...$this->present($late)],
        ];
    }

    /**
     * What the books held and the bank had not shown, as at a reconciliation's
     * statement date: lines from the account's start date up to it that neither
     * this reconciliation nor any posted one up to that date cleared. Stays true
     * for an old posted reconciliation even after later ones clear those lines
     * -- it is the history the printed statement needs.
     *
     * The list is capped; net_cents is summed by the database over every open
     * line, so the printed proof stays right when the list is cut. A voided
     * document's pair nets to nothing in that sum, as it does in the list.
     *
     * @return array{items: list<array<string, mixed>>, truncated: bool, net_cents: int}
     */
    public function openItems(BankReconciliation $recon): array
    {
        $date   = $recon->statement_date->toDateString();
        $ownIds = $recon->lines->filter(fn (BankReconciliationLine $l): bool => $l->status->clears())
            ->pluck('journal_line_id')->all();
        // Before any reconciliation of the account is posted, this (draft) one
        // is the first, and its own start date is where matching begins.
        $cutoff = $this->lastPosted($recon->bank_account_id) === null && $recon->status === BankReconciliationStatus::Draft
            ? $recon->date_from?->toDateString()
            : $this->accountCutoff($recon->bank_account_id);

        $query = fn (): Builder => DB::table('fin_journal_lines as l')
            ->join('fin_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.ledger_account_id', $recon->bank_account_id)
            ->whereIn('e.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereNull('e.deleted_at')
            ->whereDate('e.journal_date', '<=', $date)
            ->when($cutoff !== null, fn ($q) => $q->whereDate('e.journal_date', '>=', $cutoff))
            ->when($ownIds !== [], fn ($q) => $q->whereNotIn('l.id', $ownIds))
            ->whereNotExists(fn (Builder $q) => $this->clearedBy($q, $date));

        $rows = $query()
            ->select(self::LINE_COLUMNS)
            ->orderBy('e.journal_date')
            ->orderBy('l.id')
            ->limit(self::MAX_WORKSPACE_LINES + 1)
            ->get();

        $sum = $query()->selectRaw('COALESCE(SUM(l.debit), 0) as dr, COALESCE(SUM(l.credit), 0) as cr')->first();

        return [
            'items'     => $this->present($this->withoutCancelledPairs($rows->take(self::MAX_WORKSPACE_LINES))),
            'truncated' => $rows->count() > self::MAX_WORKSPACE_LINES,
            'net_cents' => Bill::toCents((string) ((float) $sum->dr - (float) $sum->cr)),
        ];
    }

    /**
     * The proof's opening gap: what the books held before the account's start
     * date (and has not cleared since) less the opening balance typed on the
     * first reconciliation. Zero when the opening was the book balance. Not
     * zero means cheques were outstanding at go-live -- the proof shows it as
     * its own line rather than as an unexplained difference.
     */
    public function openingGapCents(BankReconciliation $recon): int
    {
        $first = BankReconciliation::query()
            ->where('bank_account_id', $recon->bank_account_id)
            ->whereIn('status', [BankReconciliationStatus::Posted->value])
            ->orderBy('statement_date')
            ->orderBy('id')
            ->first();

        // A draft first reconciliation proves against itself.
        $first ??= $recon->status === BankReconciliationStatus::Draft ? $recon : null;

        if ($first === null || $first->date_from === null) {
            return 0;
        }

        $date = $recon->statement_date->toDateString();

        $sum = DB::table('fin_journal_lines as l')
            ->join('fin_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.ledger_account_id', $recon->bank_account_id)
            ->whereIn('e.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereNull('e.deleted_at')
            ->whereDate('e.journal_date', '<', $first->date_from->toDateString())
            ->whereNotExists(fn (Builder $q) => $this->clearedBy($q, $date))
            ->when($recon->status === BankReconciliationStatus::Draft, fn ($q) => $q->whereNotIn(
                'l.id',
                $recon->lines->filter(fn (BankReconciliationLine $l): bool => $l->status->clears())->pluck('journal_line_id')->all() ?: [0],
            ))
            ->selectRaw('COALESCE(SUM(l.debit), 0) as dr, COALESCE(SUM(l.credit), 0) as cr')
            ->first();

        return Bill::toCents((string) ((float) $sum->dr - (float) $sum->cr)) - Bill::toCents((string) $first->opening_balance);
    }

    /**
     * Bank lines that were uncleared at the end of a day, across accounts --
     * the same rule as openItems(): from each account's start date up to the
     * day, and not cleared by a reconciliation that was posted (and is still
     * posted) with a statement date up to that day. A voided document's pair is
     * dropped only when both sides fall inside the window, so a cheque voided
     * later still shows as outstanding on the earlier date.
     *
     * Raw rows, with ledger_account_id, for reports. $limit + 1 rows are read
     * so the caller can tell the result was cut.
     *
     * @param  list<int> $bankAccountIds
     * @return Collection<int, object>
     */
    public function unclearedAsAt(array $bankAccountIds, string $date, ?string $instrumentType, int $limit): Collection
    {
        if ($bankAccountIds === []) {
            return collect();
        }

        $cutoffs = $this->accountCutoffs($bankAccountIds);

        $rows = DB::table('fin_journal_lines as l')
            ->join('fin_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where(function (Builder $q) use ($bankAccountIds, $cutoffs): void {
                foreach ($bankAccountIds as $id) {
                    $q->orWhere(fn (Builder $w) => $w
                        ->where('l.ledger_account_id', $id)
                        ->when(isset($cutoffs[$id]), fn ($c) => $c->whereDate('e.journal_date', '>=', $cutoffs[$id])));
                }
            })
            ->whereIn('e.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereNull('e.deleted_at')
            ->whereDate('e.journal_date', '<=', $date)
            ->when($instrumentType !== null, fn ($q) => $q->where('l.instrument_type', $instrumentType))
            ->whereNotExists(fn (Builder $q) => $this->clearedBy($q, $date))
            ->select(['l.ledger_account_id', ...self::LINE_COLUMNS])
            ->orderBy('e.journal_date')
            ->orderBy('l.id')
            ->limit($limit + 1)
            ->get();

        return $this->withoutCancelledPairs($rows);
    }

    /**
     * Add Transaction: entries to put on this statement that the list does not
     * hold.
     *
     *   by = date | cheque | amount | description -- uncleared entries of any
     *        date, including ones before the start date or after the statement
     *        date. A voided document's pair is left out.
     *   by = returned -- cheques an EARLIER reconciliation realized, for the
     *        bank's debit-back of a cheque that bounced after it cleared.
     *        Matched on cheque number or amount.
     *
     * @param  list<int> $exceptIds lines already on the screen
     * @return list<array<string, mixed>>
     */
    public function search(int $bankAccountId, string $by, string $term, array $exceptIds = []): array
    {
        $account = $this->accessibleBank($bankAccountId);
        $term    = trim($term);

        if ($term === '') {
            return [];
        }

        $like   = '%' . addcslashes($term, '%_\\') . '%';
        $number = str_replace(',', '', $term);
        $amount = is_numeric($number) ? round((float) $number, 2) : null;

        if ($by === 'returned') {
            $rows = $this->realizedChequeQuery($account->id)
                ->when($exceptIds !== [], fn ($q) => $q->whereNotIn('l.id', $exceptIds))
                ->where(fn ($q) => $q
                    ->where('l.instrument_no', 'like', $like)
                    ->when($amount !== null, fn ($w) => $w->orWhere('l.debit', $amount)->orWhere('l.credit', $amount)))
                ->orderByDesc('e.journal_date')
                ->orderByDesc('l.id')
                ->limit(50)
                ->get();

            return $this->present($rows);
        }

        $query = $this->unclearedQuery($account->id)
            ->when($exceptIds !== [], fn ($q) => $q->whereNotIn('l.id', $exceptIds));

        match ($by) {
            'date'     => $this->isDate($term) ? $query->whereDate('e.journal_date', $term) : $query->whereRaw('1 = 0'),
            'cheque'   => $query->where('l.instrument_no', 'like', $like),
            'amount'   => $amount !== null
                ? $query->where(fn ($q) => $q->where('l.debit', $amount)->orWhere('l.credit', $amount))
                : $query->whereRaw('1 = 0'),
            default    => $query->where(fn ($q) => $q
                ->where('l.description', 'like', $like)
                ->orWhere('e.narration', 'like', $like)
                ->orWhere('e.journal_no', 'like', $like)),
        };

        $rows = $query->orderBy('e.journal_date')->orderBy('l.id')->limit(50)->get();

        return $this->present($this->withoutOpenCounterparts($account->id, $rows));
    }

    /**
     * The bank-only entry types and whether each one's account is mapped, so
     * the screen can say what is missing before anyone tries to post.
     *
     * @return array<string, mixed>
     */
    public function entryOptions(): array
    {
        $types = [];

        foreach (self::ENTRY_TYPES as $value => $type) {
            try {
                $this->resolver->resolve($type['role'], null);
                $mapped = true;
            } catch (Throwable) {
                $mapped = false;
            }

            $types[] = [
                'value'     => $value,
                'label'     => $type['label'],
                'direction' => $type['direction'],
                'mapped'    => $mapped,
            ];
        }

        return ['bank_entry_types' => $types];
    }

    // ── Writing ──────────────────────────────────────────────────────────────

    public function create(BankReconciliationData $data): BankReconciliation
    {
        if ($data->request_id !== null) {
            $existing = BankReconciliation::where('request_id', $data->request_id)->first();

            if ($existing !== null) {
                $this->assertAccessible($existing);

                return $this->find($existing);
            }
        }

        try {
            $recon = DB::transaction(function () use ($data): BankReconciliation {
                $this->lockBank($data->bank_account_id);
                $this->assertOnlyDraft($data->bank_account_id, null);
                $this->assertAfterLastPosted($data->bank_account_id, $data->statement_date);
                $this->assertFirstReconRules($data);

                $recon = new BankReconciliation();
                $recon->fill($this->attributes($data));
                $recon->recon_no = $this->numbers->next(
                    DocumentNumberService::TYPE_BANK_RECONCILIATION,
                    CarbonImmutable::parse($data->statement_date),
                );
                $recon->request_id = $data->request_id;
                $recon->status     = BankReconciliationStatus::Draft;
                $recon->created_by = Auth::id();
                $recon->updated_by = Auth::id();
                $recon->save();

                $this->writeLines($recon, $data);

                $this->audit->record(BankReconciliation::AUDIT_TYPE, $recon->id, 'created', null, $this->snapshot($recon));

                return $recon;
            });
        } catch (UniqueConstraintViolationException $e) {
            $recon = $data->request_id === null
                ? null
                : BankReconciliation::where('request_id', $data->request_id)->first();

            if ($recon === null) {
                throw $e;
            }

            $this->assertAccessible($recon);
        }

        return $this->find($recon);
    }

    public function update(BankReconciliation $recon, BankReconciliationData $data): BankReconciliation
    {
        $this->assertAccessible($recon);

        if ($recon->bank_account_id !== $data->bank_account_id) {
            throw ValidationException::withMessages([
                'bank_account_id' => 'A reconciliation cannot move to another bank account. Delete this draft and start a new one.',
            ]);
        }

        DB::transaction(function () use ($recon, $data): void {
            $this->lockBank($data->bank_account_id);

            /** @var BankReconciliation $locked */
            $locked = BankReconciliation::whereKey($recon->id)->lockForUpdate()->firstOrFail();

            $this->assertStatus($locked, BankReconciliationStatus::Draft, 'edited');
            $this->assertNotStale($locked, $data->updated_at);
            $this->assertAfterLastPosted($data->bank_account_id, $data->statement_date);
            $this->assertFirstReconRules($data);

            $before = $this->snapshot($locked);

            $locked->fill($this->attributes($data));
            $locked->updated_by = Auth::id();
            $locked->save();

            $this->writeLines($locked, $data);

            [$old, $new] = $this->audit->diff($before, $this->snapshot($locked->fresh()));

            if ($new !== []) {
                $this->audit->record(BankReconciliation::AUDIT_TYPE, $locked->id, 'updated', $old, $new);
            }
        });

        return $this->find($recon->fresh());
    }

    public function delete(BankReconciliation $recon): void
    {
        $this->assertAccessible($recon);

        DB::transaction(function () use ($recon): void {
            /** @var BankReconciliation $locked */
            $locked = BankReconciliation::whereKey($recon->id)->lockForUpdate()->firstOrFail();

            $this->assertStatus($locked, BankReconciliationStatus::Draft, 'deleted');

            $this->audit->record(BankReconciliation::AUDIT_TYPE, $locked->id, 'deleted', $this->snapshot($locked), null);

            $locked->lines()->delete();
            $locked->delete();
        });
    }

    /**
     * Freeze the matching. Every line is re-read from the ledger under the
     * account lock: still uncleared, still on this account, not reversed since
     * the draft was saved. The variance must then be exactly zero.
     */
    public function post(BankReconciliation $recon): BankReconciliation
    {
        $this->assertAccessible($recon);

        DB::transaction(function () use ($recon): void {
            $this->lockBank($recon->bank_account_id);

            /** @var BankReconciliation $locked */
            $locked = BankReconciliation::with('lines')->whereKey($recon->id)->lockForUpdate()->firstOrFail();

            $this->assertStatus($locked, BankReconciliationStatus::Draft, 'posted');

            // Maker-checker: neither the person who started the draft nor the
            // one who last changed its matching may post it.
            $this->controls->assertNotMaker($locked->created_by, Auth::id());
            $this->controls->assertNotMaker($locked->updated_by, Auth::id());

            $this->assertAfterLastPosted($locked->bank_account_id, $locked->statement_date->toDateString());

            if ($this->lastPosted($locked->bank_account_id) === null && $locked->date_from === null) {
                throw ValidationException::withMessages([
                    'date_from' => 'This is the first reconciliation of the account: enter its start date before posting.',
                ]);
            }

            $this->assertLinesStillOpen($locked);
            $this->assertLateReturnsStillOpen($locked);

            $totals = $this->totals($locked->lines, $this->openingFor($locked), Bill::toCents((string) $locked->statement_balance));

            if (abs($totals['difference_cents']) > self::TOLERANCE_CENTS) {
                throw ValidationException::withMessages([
                    'statement_balance' => 'The reconciliation does not balance: the difference is '
                        . number_format($totals['difference_cents'] / 100, 2)
                        . '. Realize the entries the statement shows, or add the missing bank entries, until it is 0.00.',
                ]);
            }

            // Returned cheques: the document that wrote each one posts its
            // bounce on the return date and reopens what the cheque had
            // settled. The bounce's own bank line is cleared here too: the
            // statement shows it.
            $handler = app(ChequeReturnHandler::class);

            foreach ($locked->lines->filter(fn (BankReconciliationLine $l): bool => $l->status->bounces()) as $line) {
                $source = DB::table('fin_journal_lines')->where('id', $line->journal_line_id)->first(['source_type', 'source_id']);

                if ($source?->source_type === null || $source->source_id === null) {
                    throw ValidationException::withMessages([
                        'lines' => 'Entry ' . $line->journal_no . ' is not linked to one cheque, so it cannot be returned here. If the bank reversed it, void its document.',
                    ]);
                }

                $journalNo = $handler->returnCheque(new ChequeReturnRequest(
                    (string) $source->source_type,
                    (int) $source->source_id,
                    CarbonImmutable::instance($line->cleared_date ?? $locked->statement_date),
                    $locked->recon_no,
                    (int) $locked->bank_account_id,
                ));

                $line->forceFill(['return_journal_no' => $journalNo])->save();

                $this->clearBounceLine($locked, $line, $journalNo);
            }

            $locked->forceFill([
                'opening_balance'     => Bill::fromCents($totals['opening_cents']),
                'cleared_deposits'    => Bill::fromCents($totals['deposit_cents']),
                'cleared_withdrawals' => Bill::fromCents($totals['withdrawal_cents']),
                'closing_balance'     => Bill::fromCents($totals['closing_cents']),
                'difference'          => Bill::fromCents($totals['difference_cents']),
                'book_balance'        => Bill::fromCents($this->bookBalanceCents($locked->bank_account_id, $locked->statement_date->toDateString())),
                'status'              => BankReconciliationStatus::Posted,
                'posted_at'           => now(),
                'posted_by'           => Auth::id(),
            ])->save();

            $this->audit->record(
                BankReconciliation::AUDIT_TYPE,
                $locked->id,
                'posted',
                ['status' => BankReconciliationStatus::Draft->value],
                ['status' => BankReconciliationStatus::Posted->value, 'statement_balance' => (string) $locked->statement_balance],
            );
        });

        return $this->find($recon->fresh());
    }

    /**
     * Undo the latest posted reconciliation of an account: its lines become
     * uncleared again. Only the latest, so the opening-balance chain never
     * breaks, and never one with a returned cheque, whose bounce entry is a
     * real posting that must be reversed on its own document first.
     *
     * A draft already started for the next statement is built on this one's
     * closing balance, so it is deleted with it.
     */
    public function void(BankReconciliation $recon, string $reason): BankReconciliation
    {
        $this->assertAccessible($recon);

        DB::transaction(function () use ($recon, $reason): void {
            $this->lockBank($recon->bank_account_id);

            /** @var BankReconciliation $locked */
            $locked = BankReconciliation::whereKey($recon->id)->lockForUpdate()->firstOrFail();

            $this->assertStatus($locked, BankReconciliationStatus::Posted, 'undone');

            $latest = $this->lastPosted($locked->bank_account_id);

            if ($latest !== null && $latest->id !== $locked->id) {
                throw ValidationException::withMessages([
                    'status' => 'Only the latest reconciliation of this account can be undone. Undo ' . $latest->recon_no . ' first.',
                ]);
            }

            if ($locked->lines()->whereIn('status', [ReconLineStatus::Returned->value, ReconLineStatus::ReturnedLater->value])->exists()) {
                throw ValidationException::withMessages([
                    'status' => $locked->recon_no . ' returned a cheque. A bounce entry is a real posting and cannot be undone here.',
                ]);
            }

            $drafts = BankReconciliation::query()
                ->where('bank_account_id', $locked->bank_account_id)
                ->where('status', BankReconciliationStatus::Draft->value)
                ->lockForUpdate()
                ->get();

            foreach ($drafts as $draft) {
                $this->audit->record(
                    BankReconciliation::AUDIT_TYPE,
                    $draft->id,
                    'deleted',
                    $this->snapshot($draft),
                    ['reason' => 'Removed because ' . $locked->recon_no . ' was undone.'],
                );

                $draft->lines()->delete();
                $draft->delete();
            }

            $locked->forceFill([
                'status'      => BankReconciliationStatus::Voided,
                'voided_at'   => now(),
                'voided_by'   => Auth::id(),
                'void_reason' => $reason,
                'updated_by'  => Auth::id(),
            ])->save();

            $this->audit->record(
                BankReconciliation::AUDIT_TYPE,
                $locked->id,
                'voided',
                ['status' => BankReconciliationStatus::Posted->value],
                [
                    'status'         => BankReconciliationStatus::Voided->value,
                    'void_reason'    => $reason,
                    'drafts_removed' => $drafts->pluck('recon_no')->implode(', ') ?: null,
                ],
            );
        });

        return $this->find($recon->fresh());
    }

    /**
     * Post a bank-only entry found on the statement -- bank charges or
     * interest -- and hand back its bank line, ready to tick. The other side is
     * the account mapped to that role, never one the user picks, so this
     * button cannot move money between arbitrary accounts.
     *
     * A general (hand-entered) journal, so review mode never holds it: the
     * statement already proves it happened. request_id makes a double-clicked
     * or retried post return the first entry instead of posting a second; it
     * is checked under the bank account lock.
     *
     * @param  array{bank_account_id:int, entry_date:string, type:string, amount:string, description:string, reference:?string, request_id:?string} $entry
     * @return array<string, mixed> the bank line, as workspace() presents it
     */
    public function addBankEntry(array $entry): array
    {
        $bank = $this->accessibleBank((int) $entry['bank_account_id']);
        $type = self::ENTRY_TYPES[$entry['type']] ?? throw ValidationException::withMessages(['type' => 'Choose Bank Charges or Interest Income.']);

        $poster = app(JournalPoster::class);

        if (! $poster->acceptsPostings()) {
            throw ValidationException::withMessages(['entry' => 'The Finance module is not enabled, so nothing was posted.']);
        }

        try {
            $offsetId = $this->resolver->resolve($type['role'], $bank->company_id);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'type' => 'No ' . $type['role']->label() . ' account is set up. Map one under Finance > Account Mapping first.',
            ]);
        }

        $amount    = (float) $entry['amount'];
        $text      = mb_substr((string) $entry['description'], 0, 200);
        $deposit   = $type['direction'] === BankReconciliationLine::DEPOSIT;
        $requestId = $entry['request_id'] ?? null;

        $journalNo = DB::transaction(function () use ($bank, $offsetId, $amount, $text, $deposit, $entry, $requestId, $poster): string {
            $this->lockBank($bank->id);

            if ($requestId !== null) {
                $done = DB::table('fin_journal_entries')->where('request_id', $requestId)->value('journal_no');

                if ($done !== null) {
                    return (string) $done;
                }
            }

            $bankLine = ($deposit
                ? JournalLineRequest::debit($bank->id, $amount, description: $text)
                : JournalLineRequest::credit($bank->id, $amount, description: $text))
                ->withInstrument('bank_entry', $entry['reference'] ?? null);

            $otherLine = $deposit
                ? JournalLineRequest::credit($offsetId, $amount, description: $text)
                : JournalLineRequest::debit($offsetId, $amount, description: $text);

            $journalNo = (string) $poster->post(new JournalRequest(
                sourceType:  JournalRequest::SOURCE_MANUAL,
                sourceId:    null,
                journalDate: CarbonImmutable::parse($entry['entry_date']),
                lines:       [$bankLine, $otherLine],
                journalType: JournalRequest::TYPE_GENERAL,
                companyId:   $bank->company_id,
                narration:   'Bank statement entry: ' . $text,
            ));

            if ($requestId !== null) {
                DB::table('fin_journal_entries')->where('journal_no', $journalNo)->update(['request_id' => $requestId]);
            }

            return $journalNo;
        });

        $row = $this->unclearedQuery($bank->id)->where('e.journal_no', $journalNo)->first();

        if ($row === null) {
            throw ValidationException::withMessages(['entry' => $journalNo . ' is already reconciled.']);
        }

        return $this->present(collect([$row]))[0];
    }

    // ── Guards ───────────────────────────────────────────────────────────────

    public function assertAccessible(BankReconciliation $recon): void
    {
        if (! $this->companyAccess->canAccess($this->actor(), $recon->company_id)) {
            throw new NotFoundHttpException();
        }
    }

    /** An active Bank account of a company this user may see; anything else does not exist. */
    private function accessibleBank(int $bankAccountId): LedgerAccount
    {
        $account = LedgerAccount::query()->bankAccounts()->whereKey($bankAccountId)->first();

        if ($account === null || ! $this->companyAccess->canAccess($this->actor(), $account->company_id)) {
            throw new NotFoundHttpException();
        }

        return $account;
    }

    private function assertStatus(BankReconciliation $recon, BankReconciliationStatus $required, string $action): void
    {
        if ($recon->status !== $required) {
            throw ValidationException::withMessages([
                'status' => $recon->recon_no . ' is ' . $recon->status->label() . ' and cannot be ' . $action . '.',
            ]);
        }
    }

    private function assertNotStale(BankReconciliation $recon, ?string $seenUpdatedAt): void
    {
        if ($seenUpdatedAt === null || $recon->updated_at === null) {
            return;
        }

        try {
            $seen = CarbonImmutable::parse($seenUpdatedAt);
        } catch (Throwable) {
            return;
        }

        if ($seen->getTimestamp() !== $recon->updated_at->getTimestamp()) {
            throw new HttpException(409, 'This reconciliation was changed by someone else. Reload it and try again.');
        }
    }

    /** One account, one draft: two people matching the same statement would clear lines twice. */
    private function assertOnlyDraft(int $bankAccountId, ?int $exceptId): void
    {
        $other = BankReconciliation::query()
            ->where('bank_account_id', $bankAccountId)
            ->where('status', BankReconciliationStatus::Draft->value)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->value('recon_no');

        if ($other !== null) {
            throw ValidationException::withMessages([
                'bank_account_id' => $other . ' is already open as a draft for this account. Continue that one, or delete it first.',
            ]);
        }
    }

    /** Statements are reconciled in order: each one after the last posted. */
    private function assertAfterLastPosted(int $bankAccountId, string $statementDate): void
    {
        $last = $this->lastPosted($bankAccountId);

        if ($last !== null && $statementDate <= $last->statement_date->toDateString()) {
            throw ValidationException::withMessages([
                'statement_date' => 'This account is reconciled up to ' . $last->statement_date->format('d-m-Y')
                    . ' (' . $last->recon_no . '). Choose a later statement date.',
            ]);
        }
    }

    /**
     * The first reconciliation of an account sets where matching starts: a
     * start date, and the statement's balance on the day before it. Neither
     * may be left out -- an opening that silently becomes 0.00 is how the
     * books and the bank drift apart from day one.
     */
    private function assertFirstReconRules(BankReconciliationData $data): void
    {
        if ($this->lastPosted($data->bank_account_id) !== null) {
            return;
        }

        $errors = [];

        if ($data->date_from === null) {
            $errors['date_from'] = 'This is the first reconciliation of the account: enter the start date. Entries before it are treated as already cleared.';
        }

        if ($data->opening_balance === null) {
            $errors['opening_balance'] = 'This is the first reconciliation of the account: enter the opening balance shown on the bank statement.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Each line the draft clears is still uncleared, its journal has not been
     * reversed since the draft was saved (the document was voided), and a
     * reversal line is not cleared while the line it reverses is still open --
     * together the pair is nothing the bank will ever show.
     */
    private function assertLinesStillOpen(BankReconciliation $recon): void
    {
        $clearing = $recon->lines->filter(fn (BankReconciliationLine $l): bool => $l->status->clears());

        if ($clearing->isEmpty()) {
            return;
        }

        $open = $this->unclearedQuery($recon->bank_account_id)
            ->whereIn('l.id', $clearing->pluck('journal_line_id')->all())
            ->get()
            ->keyBy('id');

        foreach ($clearing as $line) {
            $row = $open->get($line->journal_line_id);

            if ($row === null) {
                throw ValidationException::withMessages([
                    'lines' => 'Entry ' . $line->journal_no . ' was reconciled elsewhere since this draft was saved. Reload and try again.',
                ]);
            }

            if ($row->reversed_by_journal_id !== null && $line->status === ReconLineStatus::Realized) {
                throw ValidationException::withMessages([
                    'lines' => 'Entry ' . $line->journal_no . ' has been reversed (its document was voided) since this draft was saved. Reload and try again.',
                ]);
            }
        }

        $this->assertNoOrphanReversal($recon->bank_account_id, $open->values(), $clearing->pluck('journal_line_id')->all());
    }

    /**
     * A reversal line may clear only once the line it reverses has cleared (or
     * clears here too). Otherwise the user would be ticking half of a voided
     * pair: a phantom amount on the closing balance.
     *
     * @param Collection<int, object> $rows
     * @param list<int>               $clearingIds
     */
    private function assertNoOrphanReversal(int $bankAccountId, Collection $rows, array $clearingIds): void
    {
        $reversals = $rows->filter(fn (object $r): bool => $r->reverses_journal_id !== null);

        if ($reversals->isEmpty()) {
            return;
        }

        $originals = $this->unclearedQuery($bankAccountId)
            ->whereIn('l.journal_entry_id', $reversals->pluck('reverses_journal_id')->unique()->all())
            ->get()
            ->keyBy(fn (object $r): string => $r->journal_entry_id . ':' . $r->line_no);

        foreach ($reversals as $row) {
            $original = $originals->get($row->reverses_journal_id . ':' . $row->line_no);

            if ($original !== null && ! in_array((int) $original->id, $clearingIds, true)) {
                throw ValidationException::withMessages([
                    'lines' => 'Entry ' . $row->journal_no . ' reverses ' . $original->journal_no
                        . ', which is not cleared: together they cancel out and the bank will never show them. Leave both uncleared.',
                ]);
            }
        }
    }

    /** Each cheque returned after clearing is still realized, and not returned since. */
    private function assertLateReturnsStillOpen(BankReconciliation $recon): void
    {
        $late = $recon->lines->filter(fn (BankReconciliationLine $l): bool => $l->status === ReconLineStatus::ReturnedLater);

        if ($late->isEmpty()) {
            return;
        }

        $open = $this->realizedChequeQuery($recon->bank_account_id)
            ->whereIn('l.id', $late->pluck('journal_line_id')->all())
            ->pluck('l.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($late as $line) {
            if (! in_array($line->journal_line_id, $open, true)) {
                throw ValidationException::withMessages([
                    'lines' => 'Cheque ' . ($line->instrument_no ?? $line->journal_no) . ' is no longer a realized cheque of this account. Reload and try again.',
                ]);
            }
        }
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Bank lines of one account that no posted reconciliation has cleared,
     * with what the screen shows about each.
     */
    private function unclearedQuery(int $bankAccountId): Builder
    {
        return DB::table('fin_journal_lines as l')
            ->join('fin_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.ledger_account_id', $bankAccountId)
            ->whereIn('e.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereNull('e.deleted_at')
            ->whereNotExists(fn (Builder $q) => $this->clearedBy($q, null))
            ->select(self::LINE_COLUMNS);
    }

    /**
     * Cheques of one account that a posted reconciliation realized and no
     * reconciliation has returned since -- what a bank's later debit-back can
     * name. Only a single cheque with its own document can bounce.
     */
    private function realizedChequeQuery(int $bankAccountId): Builder
    {
        return DB::table('fin_journal_lines as l')
            ->join('fin_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('fin_bank_reconciliation_lines as crl', 'crl.journal_line_id', '=', 'l.id')
            ->join('fin_bank_reconciliations as cr', 'cr.id', '=', 'crl.bank_reconciliation_id')
            ->where('l.ledger_account_id', $bankAccountId)
            ->where('l.instrument_type', PaymentModeType::Cheque->value)
            ->whereNotNull('l.source_type')
            ->whereNotNull('l.source_id')
            ->where('e.status', JournalStatus::Posted->value)
            ->whereNull('e.deleted_at')
            ->where('crl.status', ReconLineStatus::Realized->value)
            ->where('cr.status', BankReconciliationStatus::Posted->value)
            ->whereNull('cr.deleted_at')
            ->whereNotExists(fn (Builder $q) => $q
                ->from('fin_bank_reconciliation_lines as rl')
                ->join('fin_bank_reconciliations as r', 'r.id', '=', 'rl.bank_reconciliation_id')
                ->whereColumn('rl.journal_line_id', 'l.id')
                ->where('r.status', BankReconciliationStatus::Posted->value)
                ->whereNull('r.deleted_at')
                ->where('rl.status', ReconLineStatus::ReturnedLater->value))
            ->select([...self::LINE_COLUMNS, 'cr.recon_no as cleared_recon_no', 'cr.statement_date as cleared_statement_date']);
    }

    /**
     * NOT EXISTS body: the line was realized or returned on a posted
     * reconciliation (with a statement date up to $asAt, when given).
     */
    private function clearedBy(Builder $q, ?string $asAt): Builder
    {
        return $q->from('fin_bank_reconciliation_lines as rl')
            ->join('fin_bank_reconciliations as r', 'r.id', '=', 'rl.bank_reconciliation_id')
            ->whereColumn('rl.journal_line_id', 'l.id')
            ->where('r.status', BankReconciliationStatus::Posted->value)
            ->whereNull('r.deleted_at')
            ->when($asAt !== null, fn ($w) => $w->whereDate('r.statement_date', '<=', $asAt))
            ->whereIn('rl.status', ReconLineStatus::clearing());
    }

    /**
     * Where matching starts for an account: the start date of its first posted
     * reconciliation. Null before one is posted, and for an account whose first
     * reconciliation predates start dates.
     */
    public function accountCutoff(int $bankAccountId): ?string
    {
        return $this->accountCutoffs([$bankAccountId])[$bankAccountId] ?? null;
    }

    /**
     * @param  list<int> $bankAccountIds
     * @return array<int, string> account id => start date (accounts with none are left out)
     */
    private function accountCutoffs(array $bankAccountIds): array
    {
        $firsts = BankReconciliation::query()
            ->whereIn('bank_account_id', $bankAccountIds)
            ->where('status', BankReconciliationStatus::Posted->value)
            ->orderBy('statement_date')
            ->orderBy('id')
            ->get(['bank_account_id', 'date_from'])
            ->unique('bank_account_id');

        $cutoffs = [];

        foreach ($firsts as $first) {
            if ($first->date_from !== null) {
                $cutoffs[(int) $first->bank_account_id] = $first->date_from->toDateString();
            }
        }

        return $cutoffs;
    }

    /**
     * Drop a voided document's line and its mirror when neither has cleared:
     * together they are nothing, and neither will ever be on a statement.
     *
     * @param  Collection<int, object> $rows
     * @return Collection<int, object>
     */
    private function withoutCancelledPairs(Collection $rows): Collection
    {
        $byEntryLine = $rows->keyBy(fn (object $r): string => $r->journal_entry_id . ':' . $r->line_no);
        $hidden      = [];

        foreach ($rows as $row) {
            if ($row->reversed_by_journal_id === null) {
                continue;
            }

            $mirror = $byEntryLine->get($row->reversed_by_journal_id . ':' . $row->line_no);

            if ($mirror !== null) {
                $hidden[$row->id]    = true;
                $hidden[$mirror->id] = true;
            }
        }

        return $rows->reject(fn (object $r): bool => isset($hidden[$r->id]))->values();
    }

    /**
     * Search results: a line whose counterpart (the line it reverses, or the
     * reversal of it) is also uncleared is half of a voided pair, so it is not
     * offered -- the counterpart need not be among the results.
     *
     * @param  Collection<int, object> $rows
     * @return Collection<int, object>
     */
    private function withoutOpenCounterparts(int $bankAccountId, Collection $rows): Collection
    {
        $counterpartEntries = $rows
            ->map(fn (object $r) => $r->reversed_by_journal_id ?? $r->reverses_journal_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($counterpartEntries === []) {
            return $rows;
        }

        $open = $this->unclearedQuery($bankAccountId)
            ->whereIn('l.journal_entry_id', $counterpartEntries)
            ->get()
            ->keyBy(fn (object $r): string => $r->journal_entry_id . ':' . $r->line_no);

        return $rows->reject(function (object $r) use ($open): bool {
            $other = $r->reversed_by_journal_id ?? $r->reverses_journal_id;

            return $other !== null && $open->has($other . ':' . $r->line_no);
        })->values();
    }

    /**
     * Rows as the screen and the API show them.
     *
     * @param  Collection<int, object> $rows
     * @return list<array<string, mixed>>
     */
    private function present(Collection $rows): array
    {
        $rows    = $rows->filter()->values();
        $handler = app(ChequeReturnHandler::class);

        $blocked = $handler->blockedReasons(
            $rows->filter(fn (object $r): bool => $r->source_type !== null && $r->source_id !== null)
                ->map(fn (object $r): array => ['source_type' => $r->source_type, 'source_id' => (int) $r->source_id])
                ->values()
                ->all(),
        );

        return $rows->map(function (object $r) use ($blocked, $handler): array {
            $deposit = (float) $r->debit > 0;
            $key     = $handler::key($r->source_type, $r->source_id === null ? null : (int) $r->source_id);
            $late    = isset($r->cleared_recon_no);

            return [
                'journal_line_id'        => (int) $r->id,
                'journal_no'             => $r->journal_no,
                'journal_date'           => substr((string) $r->journal_date, 0, 10),
                'direction'              => $deposit ? BankReconciliationLine::DEPOSIT : BankReconciliationLine::WITHDRAWAL,
                'amount'                 => number_format($deposit ? (float) $r->debit : (float) $r->credit, 2, '.', ''),
                'instrument_type'        => $r->instrument_type,
                'instrument_no'          => $r->instrument_no,
                'instrument_date'        => $r->instrument_date === null ? null : substr((string) $r->instrument_date, 0, 10),
                'description'            => $r->description ?: $r->narration,
                'narration'              => $r->narration,
                'is_reversal'            => $r->reverses_journal_id !== null,
                'is_reversed'            => $r->reversed_by_journal_id !== null,
                'cleared_before'         => $late,
                'cleared_recon_no'       => $late ? $r->cleared_recon_no : null,
                'cleared_statement_date' => $late ? substr((string) $r->cleared_statement_date, 0, 10) : null,
                'return_blocked'         => $r->source_type === null || $r->source_id === null
                    ? 'Not linked to one cheque, so it cannot be returned here. If the bank reversed it, void its document.'
                    : ($blocked[$key] ?? null),
            ];
        })->values()->all();
    }

    /**
     * Replace the draft's lines with what the user marked, each re-read from
     * the ledger: an id that is not an uncleared line of this account (or, for
     * a late return, a realized cheque of it) is refused, and the amount, date
     * and cheque come from the ledger.
     */
    private function writeLines(BankReconciliation $recon, BankReconciliationData $data): void
    {
        $recon->lines()->delete();

        if ($data->lines === []) {
            $this->storeTotals($recon);

            return;
        }

        $lateIds = array_column(array_filter($data->lines, fn (array $l): bool => $l['status'] === ReconLineStatus::ReturnedLater->value), 'journal_line_id');
        $openIds = array_column(array_filter($data->lines, fn (array $l): bool => $l['status'] !== ReconLineStatus::ReturnedLater->value), 'journal_line_id');

        $rows = $openIds === []
            ? collect()
            : $this->unclearedQuery($recon->bank_account_id)->whereIn('l.id', $openIds)->get()->keyBy('id');
        $lateRows = $lateIds === []
            ? collect()
            : $this->realizedChequeQuery($recon->bank_account_id)->whereIn('l.id', $lateIds)->get()->keyBy('id');

        $bouncing = $rows->concat($lateRows)->filter(fn (object $r): bool => $r->source_type !== null && $r->source_id !== null);
        $handler  = app(ChequeReturnHandler::class);
        $blocked  = $handler->blockedReasons($bouncing->map(fn (object $r): array => [
            'source_type' => $r->source_type,
            'source_id'   => (int) $r->source_id,
        ])->values()->all());

        $last   = $this->lastPosted($recon->bank_account_id, $recon->id);
        $errors = [];
        $insert = [];
        $now    = now();

        foreach ($data->lines as $i => $line) {
            $status = ReconLineStatus::from($line['status']);
            $row    = $status === ReconLineStatus::ReturnedLater
                ? $lateRows->get($line['journal_line_id'])
                : $rows->get($line['journal_line_id']);

            if ($row === null) {
                $errors["lines.{$i}.journal_line_id"] = $status === ReconLineStatus::ReturnedLater
                    ? 'This is not a cheque an earlier reconciliation of this account realized, or it was already returned.'
                    : 'This entry is already reconciled, or is not on this bank account.';

                continue;
            }

            $clearedDate = match ($status) {
                ReconLineStatus::NotPresented => null,
                ReconLineStatus::Realized     => $data->statement_date,
                default                       => $line['return_date'] ?? $data->statement_date,
            };

            if ($status->bounces()) {
                $reason = $row->source_type === null || $row->source_id === null
                    ? 'Not linked to one cheque, so it cannot be returned here. If the bank reversed it, void its document.'
                    : ($blocked[$handler::key($row->source_type, (int) $row->source_id)] ?? null);

                $error = $reason ?? $this->returnDateError($clearedDate, substr((string) $row->journal_date, 0, 10), $last);

                if ($error !== null) {
                    $errors["lines.{$i}.status"] = 'Cheque ' . ($row->instrument_no ?? $row->journal_no) . ': ' . $error;

                    continue;
                }
            }

            $deposit = (float) $row->debit > 0;

            $insert[] = [
                'bank_reconciliation_id' => $recon->id,
                'journal_line_id'        => (int) $row->id,
                'direction'              => $deposit ? BankReconciliationLine::DEPOSIT : BankReconciliationLine::WITHDRAWAL,
                'status'                 => $status->value,
                'cleared_date'           => $clearedDate,
                'journal_no'             => $row->journal_no,
                'journal_date'           => substr((string) $row->journal_date, 0, 10),
                'instrument_type'        => $row->instrument_type,
                'instrument_no'          => $row->instrument_no,
                'instrument_date'        => $row->instrument_date === null ? null : substr((string) $row->instrument_date, 0, 10),
                'description'            => mb_substr((string) ($row->description ?: $row->narration), 0, 255) ?: null,
                'amount'                 => number_format($deposit ? (float) $row->debit : (float) $row->credit, 2, '.', ''),
                'created_at'             => $now,
                'updated_at'             => $now,
            ];
        }

        if ($errors === []) {
            $clearing = array_values(array_map(
                fn (array $r): int => $r['journal_line_id'],
                array_filter($insert, fn (array $r): bool => in_array($r['status'], ReconLineStatus::clearing(), true)),
            ));

            try {
                $this->assertNoOrphanReversal(
                    $recon->bank_account_id,
                    $rows->filter(fn (object $r): bool => in_array((int) $r->id, $clearing, true))->values(),
                    $clearing,
                );
            } catch (ValidationException $e) {
                $errors['lines'] = $e->errors()['lines'][0] ?? $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        foreach (array_chunk($insert, 500) as $chunk) {
            BankReconciliationLine::insert($chunk);
        }

        $this->storeTotals($recon->fresh('lines'));
    }

    /**
     * When a cheque may be shown as returned: not before it was booked, after
     * the account's last reconciled statement (that period is closed for the
     * bank), and in an open finance period -- the bounce entry is dated on it.
     */
    private function returnDateError(string $returnDate, string $bookedOn, ?BankReconciliation $last): ?string
    {
        if (! $this->isDate($returnDate)) {
            return 'enter a valid return date.';
        }

        if ($returnDate < $bookedOn) {
            return 'the return date cannot be before the cheque was booked (' . CarbonImmutable::parse($bookedOn)->format('d-m-Y') . ').';
        }

        if ($last !== null && $returnDate <= $last->statement_date->toDateString()) {
            return 'the return date must be after ' . $last->statement_date->format('d-m-Y')
                . ', the last reconciled statement (' . $last->recon_no . ').';
        }

        if (! $this->controls->isOpen(CarbonImmutable::parse($returnDate))) {
            return 'the return date is in a closed finance period.';
        }

        return null;
    }

    /**
     * Put the bounce entry's bank line on the reconciliation as Returned, so
     * it never shows as an uncleared item: the statement shows it.
     */
    private function clearBounceLine(BankReconciliation $recon, BankReconciliationLine $cheque, ?string $journalNo): void
    {
        if ($journalNo === null) {
            return;
        }

        $row = DB::table('fin_journal_lines as l')
            ->join('fin_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.journal_no', $journalNo)
            ->where('l.ledger_account_id', $recon->bank_account_id)
            ->first(['l.id', 'l.debit', 'l.credit', 'l.instrument_type', 'l.instrument_no', 'l.instrument_date', 'l.description', 'e.journal_date']);

        if ($row === null) {
            return;
        }

        $deposit = (float) $row->debit > 0;

        $recon->lines()->create([
            'journal_line_id'   => (int) $row->id,
            'direction'         => $deposit ? BankReconciliationLine::DEPOSIT : BankReconciliationLine::WITHDRAWAL,
            'status'            => ReconLineStatus::Returned,
            'cleared_date'      => $cheque->cleared_date ?? $recon->statement_date,
            'journal_no'        => $journalNo,
            'journal_date'      => substr((string) $row->journal_date, 0, 10),
            'instrument_type'   => $row->instrument_type,
            'instrument_no'     => $row->instrument_no,
            'instrument_date'   => $row->instrument_date === null ? null : substr((string) $row->instrument_date, 0, 10),
            'description'       => mb_substr((string) $row->description, 0, 255) ?: null,
            'amount'            => number_format($deposit ? (float) $row->debit : (float) $row->credit, 2, '.', ''),
            'return_journal_no' => $journalNo,
        ]);
    }

    /** The draft's balances, restated from its lines every save. */
    private function storeTotals(BankReconciliation $recon): void
    {
        $recon->loadMissing('lines');
        $totals = $this->totals($recon->lines, $this->openingFor($recon), Bill::toCents((string) $recon->statement_balance));

        // The opening is the typed one on a first reconciliation, else the last
        // posted statement balance. Safe to keep on a draft: undoing that
        // reconciliation deletes this draft with it.
        $recon->forceFill([
            'opening_balance'     => Bill::fromCents($totals['opening_cents']),
            'cleared_deposits'    => Bill::fromCents($totals['deposit_cents']),
            'cleared_withdrawals' => Bill::fromCents($totals['withdrawal_cents']),
            'closing_balance'     => Bill::fromCents($totals['closing_cents']),
            'difference'          => Bill::fromCents($totals['difference_cents']),
        ])->save();
    }

    /**
     * Money in and out on this statement: lines realized here, plus cheques an
     * earlier statement realized that the bank returned on this one -- a
     * deposited cheque coming back is money out, an issued one coming back is
     * money in.
     *
     * @param  \Illuminate\Support\Collection<int, BankReconciliationLine> $lines
     * @return array{opening_cents:int, deposit_cents:int, withdrawal_cents:int, closing_cents:int, difference_cents:int}
     */
    private function totals($lines, int $openingCents, int $statementCents): array
    {
        $deposits    = 0;
        $withdrawals = 0;

        foreach ($lines as $line) {
            $cents = Bill::toCents((string) $line->amount);
            $in    = $line->direction === BankReconciliationLine::DEPOSIT;

            if ($line->status === ReconLineStatus::Realized) {
                $in ? $deposits += $cents : $withdrawals += $cents;
            } elseif ($line->status === ReconLineStatus::ReturnedLater) {
                $in ? $withdrawals += $cents : $deposits += $cents;
            }
        }

        $closing = $openingCents + $deposits - $withdrawals;

        return [
            'opening_cents'    => $openingCents,
            'deposit_cents'    => $deposits,
            'withdrawal_cents' => $withdrawals,
            'closing_cents'    => $closing,
            'difference_cents' => $statementCents - $closing,
        ];
    }

    /**
     * The opening balance: the last posted statement balance of the account,
     * or -- on its very first reconciliation -- what the user typed.
     */
    private function openingFor(BankReconciliation $recon): int
    {
        $last = $this->lastPosted($recon->bank_account_id, $recon->id);

        return $last === null ? Bill::toCents((string) $recon->opening_balance) : Bill::toCents((string) $last->statement_balance);
    }

    private function lastPosted(int $bankAccountId, ?int $exceptId = null): ?BankReconciliation
    {
        return BankReconciliation::query()
            ->where('bank_account_id', $bankAccountId)
            ->where('status', BankReconciliationStatus::Posted->value)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->orderByDesc('statement_date')
            ->orderByDesc('id')
            ->first();
    }

    /** Ledger balance of the account at the end of a day, in cents. */
    public function bookBalanceCents(int $bankAccountId, string $date): int
    {
        $row = DB::table('fin_journal_lines as l')
            ->join('fin_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.ledger_account_id', $bankAccountId)
            ->whereIn('e.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereNull('e.deleted_at')
            ->whereDate('e.journal_date', '<=', $date)
            ->selectRaw('COALESCE(SUM(l.debit), 0) as dr, COALESCE(SUM(l.credit), 0) as cr')
            ->first();

        return Bill::toCents((string) ((float) $row->dr - (float) $row->cr));
    }

    /**
     * Lines realized on this reconciliation that were booked AFTER its
     * statement date, net (deposits less withdrawals). The bank shows them,
     * the book balance on the statement date does not -- the printed proof
     * names them instead of calling them a difference.
     */
    public function bookedAfterCents(BankReconciliation $recon): int
    {
        $date  = $recon->statement_date->toDateString();
        $cents = 0;

        foreach ($recon->lines as $line) {
            if ($line->status !== ReconLineStatus::Realized || $line->journal_date === null || $line->journal_date->toDateString() <= $date) {
                continue;
            }

            $amount = Bill::toCents((string) $line->amount);
            $cents += $line->direction === BankReconciliationLine::DEPOSIT ? $amount : -$amount;
        }

        return $cents;
    }

    private function lockBank(int $bankAccountId): void
    {
        LedgerAccount::query()->whereKey($bankAccountId)->lockForUpdate()->first(['id']);
    }

    /** @return array<string, mixed> */
    private function attributes(BankReconciliationData $data): array
    {
        return [
            'company_id'        => $data->company_id,
            'bank_account_id'   => $data->bank_account_id,
            'date_from'         => $data->date_from,
            'statement_date'    => $data->statement_date,
            'statement_balance' => $data->statement_balance,
            // Only the first reconciliation of an account takes a typed
            // opening; openingFor() ignores it once one has been posted, and
            // posting stamps the opening it actually used.
            'opening_balance'   => $data->opening_balance ?? '0.00',
            'notes'             => $data->notes,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(BankReconciliation $recon): array
    {
        $recon->loadMissing('lines');

        return [
            'recon_no'          => $recon->recon_no,
            'bank_account_id'   => $recon->bank_account_id,
            'date_from'         => $recon->date_from?->toDateString(),
            'statement_date'    => $recon->statement_date?->toDateString(),
            'opening_balance'   => (string) $recon->opening_balance,
            'statement_balance' => (string) $recon->statement_balance,
            'difference'        => (string) $recon->difference,
            'status'            => $recon->status?->value,
            'realized'          => $recon->lines->where('status', ReconLineStatus::Realized)->count(),
            'returned'          => $recon->lines->where('status', ReconLineStatus::Returned)->count(),
            'returned_later'    => $recon->lines->where('status', ReconLineStatus::ReturnedLater)->count(),
        ];
    }

    private function actor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            && CarbonImmutable::hasFormat($value, 'Y-m-d');
    }
}
