<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Contracts\JournalPoster;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CompanyAccessService;
use App\Services\ReceivedChequeRegistry;
use App\Support\Journal\PostingRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\DTOs\BankDepositData;
use Modules\Finance\Enums\BankDepositStatus;
use Modules\Finance\Enums\CashBookType;
use Modules\Finance\Http\Requests\BillPaymentRequest;
use Modules\Finance\Models\BankDeposit;
use Modules\Finance\Models\BankDepositLine;
use Modules\Finance\Models\Bill;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Posting\BankDepositJournal;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Bank deposits: enter, confirm (post Dr Bank / Cr Cash Book), void (reverse).
 *
 * Where the money comes from:
 *   - cash    : a Cash Book that takes cash, up to what it holds
 *   - cheques : confirmed Customer Receipt cheques received into a Cash Book
 *               ("Cheques in Hand"), read through ReceivedChequeRegistry
 *
 * One cheque, one slip: a draft deposit already holds its cheques, so no
 * second deposit can take them. Cash is not held by a draft -- like a bill
 * balance on a draft Bill Payment, it is re-checked at confirm.
 *
 * Lock order, everywhere: deposit row, then bank account row, then the
 * company's cash book rows in ascending id. Locking the cash books serialises
 * every deposit drawing on them, so two deposits can never both take the same
 * cheque or both spend the same cash.
 */
class BankDepositService
{
    private const EAGER = [
        'company:id,company_name',
        'bankAccount:id,code,ledger_account_name,bank_account_no,bank_id,bank_branch_id',
        'bankAccount.bank:id,bank_name',
        'bankAccount.bankBranch:id,branch_name',
        'lines.fromAccount:id,code,ledger_account_name',
    ];

    private const SORTABLE = ['deposit_no', 'deposit_date', 'total_amount', 'created_at'];

    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly DocumentNumberService  $numbers,
        private readonly FinanceControlService  $controls,
        private readonly CompanyAccessService   $companyAccess,
        private readonly AuditLogger            $audit,
        private readonly ReceivedChequeRegistry $cheques,
        private readonly FundBalanceService     $funds,
    ) {}

    // ── Reading ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $query = BankDeposit::query()->with(self::EAGER)->withCount([
            'lines as cheque_count' => fn ($q) => $q->where('line_type', BankDepositLine::TYPE_CHEQUE),
        ]);

        $this->companyAccess->scope($query, $this->actor(), 'company_id', includeNull: false);

        if (! empty($filters['search'])) {
            $term = '%' . addcslashes((string) $filters['search'], '%_\\') . '%';
            $query->where(fn ($q) => $q
                ->where('deposit_no', 'like', $term)
                ->orWhere('slip_no', 'like', $term)
                ->orWhere('description', 'like', $term)
                ->orWhereHas('lines', fn ($l) => $l
                    ->where('cheque_no', 'like', $term)
                    ->orWhere('receipt_no', 'like', $term)
                    ->orWhere('customer_name', 'like', $term)));
        }

        if (! empty($filters['status']) && BankDepositStatus::tryFrom((string) $filters['status']) !== null) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        if (! empty($filters['bank_account_id'])) {
            $query->where('bank_account_id', (int) $filters['bank_account_id']);
        }

        if (! empty($filters['date_from']) && $this->isDate($filters['date_from'])) {
            $query->whereDate('deposit_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to']) && $this->isDate($filters['date_to'])) {
            $query->whereDate('deposit_date', '<=', $filters['date_to']);
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true) ? $filters['sort'] : 'deposit_date';
        $dir  = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $dir)->orderByDesc('id')->paginate($perPage);
    }

    public function find(BankDeposit $deposit): BankDeposit
    {
        $this->assertAccessible($deposit);

        return $deposit->load([...self::EAGER, 'attachments', 'creator:id,name']);
    }

    /** @return Collection<int, AuditLog> */
    public function history(BankDeposit $deposit): Collection
    {
        $this->assertAccessible($deposit);

        return AuditLog::with('user:id,name')
            ->where('auditable_type', BankDeposit::AUDIT_TYPE)
            ->where('auditable_id', $deposit->id)
            ->orderByDesc('id')
            ->get();
    }

    public function previewNextNo(?string $date = null): string
    {
        return $this->numbers->preview(
            DocumentNumberService::TYPE_BANK_DEPOSIT,
            $date !== null && $this->isDate($date) ? CarbonImmutable::parse($date) : CarbonImmutable::today(),
        );
    }

    /**
     * What a company has waiting to be banked: each cash book's available
     * cash, and every cheque in hand (a deposit being edited keeps its own).
     *
     * @return array{cash_accounts: list<array<string, mixed>>, cheques: list<array<string, mixed>>}
     */
    public function inHand(int $companyId, string $date, ?int $depositId = null): array
    {
        if (! $this->companyAccess->canAccess($this->actor(), $companyId)) {
            throw new NotFoundHttpException();
        }

        $cash = LedgerAccount::query()
            ->cashInHand()
            ->where('company_id', $companyId)
            ->orderBy('code')
            ->get(['id', 'code', 'ledger_account_name'])
            ->map(fn (LedgerAccount $a): array => [
                'id'        => $a->id,
                'code'      => $a->code,
                'name'      => $a->ledger_account_name,
                'available' => $this->cashAvailable($a->id, $date),
            ])
            ->values()
            ->all();

        $names = LedgerAccount::query()
            ->whereIn('id', $this->chequeSourceIds($companyId))
            ->get(['id', 'code', 'ledger_account_name'])
            ->keyBy('id');

        $cheques = array_map(function (array $row) use ($names, $date): array {
            $account = $names->get($row['ledger_account_id']);

            return [
                ...$row,
                'account'  => $account === null ? null : $account->code . ' - ' . $account->ledger_account_name,
                // The screen shows why a row cannot be ticked; the rule itself is chequeProblem().
                'blocked'  => $this->chequeProblem($row, $date),
            ];
        }, $this->cheques->chequesInHand($this->chequeSourceIds($companyId), $depositId));

        return ['cash_accounts' => $cash, 'cheques' => $cheques];
    }

    /**
     * Cash a book can give up on a date: the lower of its balance on that day
     * and its balance now, less any cheques it still holds -- those go on the
     * slip as cheques, not as cash. See FundBalanceService.
     */
    public function cashAvailable(int $accountId, string $date): string
    {
        $account = LedgerAccount::find($accountId);

        return Bill::fromCents($account === null ? 0 : $this->funds->availableCents($account, $date));
    }

    /**
     * Problems with the cash part of a deposit, keyed by field. Empty when the
     * cash is fine or there is none. Shared by BankDepositRequest and confirm.
     *
     * @return array<string, string>
     */
    public function cashErrors(?int $accountId, string $amount, int $companyId, string $date): array
    {
        $cents = Bill::toCents($amount);

        if ($cents <= 0) {
            return [];
        }

        if ($accountId === null) {
            return ['cash_account_id' => 'Choose the Cash Book the cash is taken from.'];
        }

        $account = LedgerAccount::query()->cashInHand()->whereKey($accountId)->first();

        if ($account === null || $account->company_id !== $companyId) {
            return ['cash_account_id' => 'Choose an active Cash Book of this company that takes cash.'];
        }

        $available = $this->cashAvailable($accountId, $date);

        if ($cents > Bill::toCents($available)) {
            return ['cash_amount' => 'Only ' . number_format((float) $available, 2) . ' is available in '
                . $account->ledger_account_name . ' on ' . CarbonImmutable::parse($date)->format('d-m-Y') . '.'];
        }

        return [];
    }

    /**
     * Problems with the cheques on a deposit, keyed "cheque_ids.{i}". Empty
     * when every cheque is in hand in this company and may be banked on the
     * date. Shared by BankDepositRequest and confirm.
     *
     * @param  list<int> $chequeIds
     * @return array<string, string>
     */
    public function chequeErrors(array $chequeIds, int $companyId, string $date, ?int $exceptDepositId): array
    {
        if ($chequeIds === []) {
            return [];
        }

        $inHand = collect($this->cheques->chequesInHand($this->chequeSourceIds($companyId), $exceptDepositId, $chequeIds))
            ->keyBy('settlement_id');

        $errors = [];

        foreach ($chequeIds as $i => $id) {
            $row = $inHand->get($id);

            $problem = $row === null
                ? 'This cheque is no longer in hand: it is on another deposit, or its receipt was voided.'
                : $this->chequeProblem($row, $date);

            if ($problem !== null) {
                $errors["cheque_ids.{$i}"] = $problem;
            }
        }

        return $errors;
    }

    // ── Writing ──────────────────────────────────────────────────────────────

    public function create(BankDepositData $data): BankDeposit
    {
        // Idempotent create: a retried request gets the deposit already made.
        if ($data->request_id !== null) {
            $existing = BankDeposit::withTrashed()->where('request_id', $data->request_id)->first();

            if ($existing !== null) {
                $this->assertAccessible($existing);

                return $this->find($existing);
            }
        }

        try {
            $deposit = $this->insert($data);
        } catch (UniqueConstraintViolationException $e) {
            // Two copies of the same submit raced past the check above; the
            // request_id unique index let one in. Answer with that one.
            $winner = $data->request_id === null
                ? null
                : BankDeposit::withTrashed()->where('request_id', $data->request_id)->first();

            if ($winner === null) {
                throw $e;
            }

            $this->assertAccessible($winner);
            $deposit = $winner;
        }

        return $this->find($deposit);
    }

    private function insert(BankDepositData $data): BankDeposit
    {
        return DB::transaction(function () use ($data): BankDeposit {
            $this->lockSources($data->company_id);
            $this->assertDepositable($data, null);

            $deposit = new BankDeposit();
            $deposit->fill($this->attributes($data));

            $deposit->deposit_no    = $this->numbers->next(
                DocumentNumberService::TYPE_BANK_DEPOSIT,
                CarbonImmutable::parse($data->deposit_date),
            );
            $deposit->request_id    = $data->request_id;
            $deposit->status        = BankDepositStatus::Draft;
            $deposit->currency      = 'LKR';
            $deposit->exchange_rate = 1;
            $deposit->created_by    = Auth::id();
            $deposit->updated_by    = Auth::id();
            $deposit->save();

            $this->writeLines($deposit, $data);

            $this->audit->record(BankDeposit::AUDIT_TYPE, $deposit->id, 'created', null, $this->snapshot($deposit));

            return $deposit;
        });
    }

    public function update(BankDeposit $deposit, BankDepositData $data): BankDeposit
    {
        $this->assertAccessible($deposit);

        DB::transaction(function () use ($deposit, $data): void {
            /** @var BankDeposit $locked */
            $locked = BankDeposit::whereKey($deposit->id)->lockForUpdate()->firstOrFail();

            $this->assertStatus($locked, BankDepositStatus::Draft, 'edited');
            $this->assertNotStale($locked, $data->updated_at);

            // The company may have changed; lock both sets of books.
            $this->lockSources($locked->company_id, $data->company_id);
            $this->assertDepositable($data, $locked->id);

            $before = $this->snapshot($locked);

            $locked->fill($this->attributes($data));
            $locked->updated_by = Auth::id();
            $locked->save();

            $locked->lines()->delete();
            $this->writeLines($locked, $data);

            [$old, $new] = $this->audit->diff($before, $this->snapshot($locked->fresh()));

            if ($new !== []) {
                $this->audit->record(BankDeposit::AUDIT_TYPE, $locked->id, 'updated', $old, $new);
            }
        });

        return $this->find($deposit->fresh());
    }

    /** Drafts only; deleting one puts its cheques back in hand. A confirmed deposit is voided, never deleted. */
    public function delete(BankDeposit $deposit): void
    {
        $this->assertAccessible($deposit);

        DB::transaction(function () use ($deposit): void {
            /** @var BankDeposit $locked */
            $locked = BankDeposit::whereKey($deposit->id)->lockForUpdate()->firstOrFail();

            $this->assertStatus($locked, BankDepositStatus::Draft, 'deleted');

            $this->audit->record(BankDeposit::AUDIT_TYPE, $locked->id, 'deleted', $this->snapshot($locked), null);

            $locked->delete();
        });
    }

    /** Bank it: re-check every source under lock and post the journal. */
    public function confirm(BankDeposit $deposit): BankDeposit
    {
        $this->assertAccessible($deposit);

        DB::transaction(function () use ($deposit): void {
            /** @var BankDeposit $locked */
            $locked = BankDeposit::with('lines')->whereKey($deposit->id)->lockForUpdate()->firstOrFail();

            $this->assertStatus($locked, BankDepositStatus::Draft, 'confirmed');
            $this->controls->assertNotMaker($locked->created_by, Auth::id());
            $this->controls->assertOpen($locked->deposit_date, 'deposit_date');

            $this->assertBankStillValid($locked);
            $this->lockSources($locked->company_id);
            $this->assertLinesStillDepositable($locked);

            $poster = app(JournalPoster::class);

            if (! $poster->acceptsPostings()) {
                throw ValidationException::withMessages([
                    'status' => 'The Finance module is not enabled, so this deposit cannot be posted.',
                ]);
            }

            $journalNo = $poster->post(BankDepositJournal::build($locked));

            $locked->forceFill([
                'status'       => BankDepositStatus::Confirmed,
                'journal_no'   => $journalNo,
                'confirmed_at' => now(),
                'confirmed_by' => Auth::id(),
                'updated_by'   => Auth::id(),
            ])->save();

            $this->audit->record(
                BankDeposit::AUDIT_TYPE,
                $locked->id,
                'confirmed',
                ['status' => BankDepositStatus::Draft->value],
                ['status' => BankDepositStatus::Confirmed->value, 'journal_no' => $journalNo],
            );
        });

        return $this->find($deposit->fresh());
    }

    /**
     * Reverse a confirmed deposit on a chosen date. Its cheques go back in
     * hand and may be banked again. The reversal date follows the rule every
     * Finance void follows (FinanceControlService::assertReversalDate).
     */
    public function void(BankDeposit $deposit, string $reason, string $reversalDate): BankDeposit
    {
        $this->assertAccessible($deposit);

        DB::transaction(function () use ($deposit, $reason, $reversalDate): void {
            /** @var BankDeposit $locked */
            $locked = BankDeposit::whereKey($deposit->id)->lockForUpdate()->firstOrFail();

            $this->assertStatus($locked, BankDepositStatus::Confirmed, 'voided');

            $date = $this->controls->assertReversalDate($reversalDate, $locked->deposit_date, 'deposit');

            $reversalNo = app(JournalPoster::class)->reverseDocument(
                PostingRole::SOURCE_BANK_DEPOSIT,
                (int) $locked->id,
                'Void of ' . $locked->deposit_no . ': ' . $reason,
                $date,
            );

            $locked->forceFill([
                'status'              => BankDepositStatus::Voided,
                'voided_at'           => now(),
                'voided_by'           => Auth::id(),
                'void_reason'         => $reason,
                'reversal_date'       => $date->toDateString(),
                'reversal_journal_no' => $reversalNo,
                'updated_by'          => Auth::id(),
            ])->save();

            $this->audit->record(
                BankDeposit::AUDIT_TYPE,
                $locked->id,
                'voided',
                ['status' => BankDepositStatus::Confirmed->value],
                [
                    'status'              => BankDepositStatus::Voided->value,
                    'void_reason'         => $reason,
                    'reversal_date'       => $date->toDateString(),
                    'reversal_journal_no' => $reversalNo,
                ],
            );
        });

        return $this->find($deposit->fresh());
    }

    // ── Guards ───────────────────────────────────────────────────────────────

    /** Another company's deposit does not exist, as far as this user can tell. */
    public function assertAccessible(BankDeposit $deposit): void
    {
        if (! $this->companyAccess->canAccess($this->actor(), $deposit->company_id)) {
            throw new NotFoundHttpException();
        }
    }

    private function assertStatus(BankDeposit $deposit, BankDepositStatus $required, string $action): void
    {
        if ($deposit->status !== $required) {
            throw ValidationException::withMessages([
                'status' => $deposit->deposit_no . ' is ' . $deposit->status->label() . ' and cannot be ' . $action . '.',
            ]);
        }
    }

    private function assertNotStale(BankDeposit $deposit, ?string $seenUpdatedAt): void
    {
        if ($seenUpdatedAt === null || $deposit->updated_at === null) {
            return;
        }

        try {
            $seen = CarbonImmutable::parse($seenUpdatedAt);
        } catch (\Throwable) {
            return;
        }

        if ($seen->getTimestamp() !== $deposit->updated_at->getTimestamp()) {
            throw new HttpException(409, 'This deposit was changed by someone else. Reload it and try again.');
        }
    }

    /** The request's checks, again, now that the cash books are locked. */
    private function assertDepositable(BankDepositData $data, ?int $exceptDepositId): void
    {
        $errors = [
            ...$this->cashErrors($data->cash_account_id, $data->cash_amount, $data->company_id, $data->deposit_date),
            ...$this->chequeErrors($data->cheque_ids, $data->company_id, $data->deposit_date, $exceptDepositId),
        ];

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** The bank account may have been deactivated since the draft. Locked, as the debit side. */
    private function assertBankStillValid(BankDeposit $deposit): void
    {
        $account = LedgerAccount::query()
            ->bankAccounts()
            ->whereKey($deposit->bank_account_id)
            ->lockForUpdate()
            ->first();

        if ($account === null || $account->company_id !== $deposit->company_id) {
            throw ValidationException::withMessages([
                'bank_account_id' => 'The bank account is no longer active. Edit the deposit and choose another.',
            ]);
        }
    }

    /**
     * Every line exactly as the draft recorded it: the cash still there, each
     * cheque still in hand, in the same cash book, for the same amount.
     */
    private function assertLinesStillDepositable(BankDeposit $deposit): void
    {
        $date = $deposit->deposit_date->toDateString();
        $cash = $deposit->lines->firstWhere('line_type', BankDepositLine::TYPE_CASH);

        $errors = $cash === null
            ? []
            : $this->cashErrors($cash->from_account_id, (string) $cash->amount, $deposit->company_id, $date);

        $chequeLines = $deposit->lines->where('line_type', BankDepositLine::TYPE_CHEQUE)->values();

        $inHand = collect($this->cheques->chequesInHand(
            $this->chequeSourceIds($deposit->company_id),
            $deposit->id,
            $chequeLines->pluck('source_id')->all(),
        ))->keyBy('settlement_id');

        foreach ($chequeLines as $line) {
            $row = $inHand->get($line->source_id);

            $problem = match (true) {
                $row === null => 'Cheque ' . $line->cheque_no . ' is no longer in hand: its receipt was voided. Remove it from the deposit.',
                $row['ledger_account_id'] !== $line->from_account_id
                    || Bill::toCents($row['amount']) !== Bill::toCents($line->amount)
                    => 'Cheque ' . $line->cheque_no . ' was changed on its receipt. Edit the deposit to refresh it.',
                default => $this->chequeProblem($row, $date),
            };

            if ($problem !== null) {
                $errors['cheques'] = $problem;

                break;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Why this cheque cannot be banked on this date, or null. A bank will not
     * take a post-dated cheque before its date, nor a stale one (the same
     * 6-month rule as an issued cheque); nor can money be banked before it was
     * received.
     *
     * @param array<string, mixed> $row a ReceivedChequeRegistry row
     */
    private function chequeProblem(array $row, string $date): ?string
    {
        $label = 'Cheque ' . $row['cheque_no'];

        if ($row['receipt_date'] > $date) {
            return $label . ' was received on ' . CarbonImmutable::parse($row['receipt_date'])->format('d-m-Y')
                . ', after the deposit date.';
        }

        if ($row['cheque_date'] === null) {
            return null;
        }

        $chequeDate = CarbonImmutable::parse($row['cheque_date']);

        if ($row['cheque_date'] > $date) {
            return $label . ' is post-dated to ' . $chequeDate->format('d-m-Y') . ' and cannot be deposited before then.';
        }

        if ($chequeDate->lessThan(CarbonImmutable::parse($date)->subDays(BillPaymentRequest::CHEQUE_STALE_DAYS))) {
            return $label . ' dated ' . $chequeDate->format('d-m-Y') . ' is stale (older than 6 months).';
        }

        return null;
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Every Cash Book of the company -- where a received cheque can sit.
     * Inactive ones too: a book deactivated with cheques still in it must not
     * strand them. Cheques received straight into a Bank account are already
     * banked and never appear.
     *
     * @return list<int>
     */
    private function chequeSourceIds(int $companyId): array
    {
        return LedgerAccount::query()
            ->whereNull('party_type')
            ->where('cash_book_type', CashBookType::CashBook->value)
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** Lock the cash books of one or two companies, ascending id. */
    private function lockSources(int ...$companyIds): void
    {
        LedgerAccount::query()
            ->where('cash_book_type', CashBookType::CashBook->value)
            ->whereIn('company_id', array_unique($companyIds))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    /** @return array<string, mixed> */
    private function attributes(BankDepositData $data): array
    {
        return [
            'company_id'      => $data->company_id,
            'deposit_date'    => $data->deposit_date,
            'bank_account_id' => $data->bank_account_id,
            'slip_no'         => $data->slip_no,
            'description'     => $data->description,
        ];
    }

    /** Cash first, then the cheques by date; totals restated from the lines. */
    private function writeLines(BankDeposit $deposit, BankDepositData $data): void
    {
        $lineNo      = 0;
        $cashCents   = Bill::toCents($data->cash_amount);
        $chequeCents = 0;

        if ($data->cash_account_id !== null && $cashCents > 0) {
            $deposit->lines()->create([
                'line_no'         => ++$lineNo,
                'line_type'       => BankDepositLine::TYPE_CASH,
                'from_account_id' => $data->cash_account_id,
                'amount'          => $data->cash_amount,
            ]);
        } else {
            $cashCents = 0;
        }

        $rows = $this->cheques->chequesInHand(
            $this->chequeSourceIds($data->company_id),
            $deposit->id,
            $data->cheque_ids,
        );

        foreach ($rows as $row) {
            $chequeCents += Bill::toCents($row['amount']);

            $deposit->lines()->create([
                'line_no'         => ++$lineNo,
                'line_type'       => BankDepositLine::TYPE_CHEQUE,
                'from_account_id' => $row['ledger_account_id'],
                'source_type'     => ReceivedChequeRegistry::SOURCE_CUSTOMER_RECEIPT,
                'source_id'       => $row['settlement_id'],
                'receipt_no'      => $row['receipt_no'],
                'receipt_date'    => $row['receipt_date'],
                'customer_name'   => $row['customer_name'] === null ? null : mb_substr((string) $row['customer_name'], 0, 150),
                'cheque_no'       => $row['cheque_no'],
                'cheque_date'     => $row['cheque_date'],
                'drawee_bank'     => $row['drawee_bank'] === null ? null : mb_substr((string) $row['drawee_bank'], 0, 100),
                'amount'          => $row['amount'],
            ]);
        }

        $deposit->forceFill([
            'cash_total'   => Bill::fromCents($cashCents),
            'cheque_total' => Bill::fromCents($chequeCents),
            'total_amount' => Bill::fromCents($cashCents + $chequeCents),
        ])->save();
    }

    /** @return array<string, mixed> What the audit trail records for a deposit. */
    private function snapshot(BankDeposit $deposit): array
    {
        $deposit->load('lines');

        return [
            'deposit_no'      => $deposit->deposit_no,
            'company_id'      => $deposit->company_id,
            'deposit_date'    => $deposit->deposit_date?->toDateString(),
            'bank_account_id' => $deposit->bank_account_id,
            'slip_no'         => $deposit->slip_no,
            'description'     => $deposit->description,
            'cash_total'      => (string) $deposit->cash_total,
            'cheque_total'    => (string) $deposit->cheque_total,
            'total_amount'    => (string) $deposit->total_amount,
            'status'          => $deposit->status?->value,
            'lines'           => $deposit->lines->map(fn (BankDepositLine $l): array => [
                'type'            => $l->line_type,
                'from_account_id' => $l->from_account_id,
                'cheque_no'       => $l->cheque_no,
                'amount'          => (string) $l->amount,
            ])->values()->all(),
        ];
    }

    private function actor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false;
    }
}
