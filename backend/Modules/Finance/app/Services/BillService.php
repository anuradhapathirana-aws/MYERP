<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Contracts\JournalPoster;
use App\Models\AuditLog;
use App\Models\SupplierMaster;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CompanyAccessService;
use App\Support\Journal\PostingRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\DTOs\BillData;
use Modules\Finance\Enums\BillStatus;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Models\Bill;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Posting\BillJournal;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Supplier bills: enter, confirm (post), void (reverse).
 *
 * Every state change runs in one transaction with the bill row locked, so a
 * double-clicked Confirm or two users acting at once cannot post twice or
 * void a bill that is being confirmed. The journal's unique
 * (source_type, source_id) index is the second line of defence.
 *
 * Each change writes an audit row inside the same transaction: if the change
 * rolls back, so does its history.
 */
class BillService
{
    private const EAGER = ['supplier:id,supplier_code,supplier_name', 'company:id,company_name', 'location:id,location_name', 'lines.ledgerAccount:id,code,ledger_account_name'];

    /** Columns the list may be sorted by. Anything else is ignored. */
    private const SORTABLE = ['bill_no', 'transaction_date', 'due_date', 'total_amount', 'document_no', 'created_at'];

    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly FinanceControlService $controls,
        private readonly CompanyAccessService  $companyAccess,
        private readonly AuditLogger           $audit,
    ) {}

    // ── Reading ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $query = Bill::query()->with(self::EAGER);

        $this->companyAccess->scope($query, $this->actor(), 'company_id', includeNull: false);

        if (! empty($filters['search'])) {
            $term = '%' . addcslashes((string) $filters['search'], '%_\\') . '%';
            $query->where(fn ($q) => $q
                ->where('bill_no', 'like', $term)
                ->orWhere('document_no', 'like', $term)
                ->orWhere('description', 'like', $term)
                ->orWhereHas('supplier', fn ($s) => $s->where('supplier_name', 'like', $term)));
        }

        if (! empty($filters['status']) && BillStatus::tryFrom((string) $filters['status']) !== null) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['payment_status']) && PaymentStatus::tryFrom((string) $filters['payment_status']) !== null) {
            $query->where('payment_status', $filters['payment_status']);
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        if (! empty($filters['date_from']) && $this->isDate($filters['date_from'])) {
            $query->whereDate('transaction_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to']) && $this->isDate($filters['date_to'])) {
            $query->whereDate('transaction_date', '<=', $filters['date_to']);
        }

        if (! empty($filters['overdue'])) {
            $query->overdue();
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true) ? $filters['sort'] : 'transaction_date';
        $dir  = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $dir)->orderByDesc('id')->paginate($perPage);
    }

    public function find(Bill $bill): Bill
    {
        $this->assertAccessible($bill);

        return $bill->load([
            ...self::EAGER,
            'attachments',
            'creator:id,name',
            'paymentAllocations.payment:id,payment_no,payment_date,payment_mode_name,status',
        ]);
    }

    /** @return Collection<int, AuditLog> */
    public function history(Bill $bill): Collection
    {
        $this->assertAccessible($bill);

        return AuditLog::with('user:id,name')
            ->where('auditable_type', Bill::AUDIT_TYPE)
            ->where('auditable_id', $bill->id)
            ->orderByDesc('id')
            ->get();
    }

    public function previewNextNo(?string $date = null): string
    {
        return $this->numbers->preview(
            DocumentNumberService::TYPE_BILL,
            $date !== null && $this->isDate($date) ? CarbonImmutable::parse($date) : CarbonImmutable::today(),
        );
    }

    /**
     * What the form pre-fills when a supplier is picked: due date from the
     * supplier's credit period, and the supplier group's default expense
     * account if it is billable.
     *
     * @return array{due_date:string, credit_period:int, ledger_account_id:int|null}
     */
    public function supplierDefaults(SupplierMaster $supplier, ?string $date = null): array
    {
        $from   = $date !== null && $this->isDate($date) ? CarbonImmutable::parse($date) : CarbonImmutable::today();
        $period = max(0, (int) $supplier->credit_period);

        $accountId = $supplier->group?->default_expense_account_id;

        if ($accountId !== null && ! LedgerAccount::billable()->whereKey($accountId)->exists()) {
            $accountId = null;
        }

        return [
            'due_date'          => $from->addDays($period)->toDateString(),
            'credit_period'     => $period,
            'ledger_account_id' => $accountId,
        ];
    }

    // ── Writing ──────────────────────────────────────────────────────────────

    public function create(BillData $data): Bill
    {
        // Idempotent create: a retried request (double click, flaky network)
        // carries the same request_id and gets the bill already made.
        if ($data->request_id !== null) {
            $existing = Bill::withTrashed()->where('request_id', $data->request_id)->first();

            if ($existing !== null) {
                $this->assertAccessible($existing);

                return $this->find($existing);
            }
        }

        try {
            $bill = $this->insert($data);
        } catch (UniqueConstraintViolationException $e) {
            // Two copies of the same submit raced past the check above; the
            // request_id unique index let one in. Answer with that one.
            $winner = $data->request_id === null
                ? null
                : Bill::withTrashed()->where('request_id', $data->request_id)->first();

            if ($winner === null) {
                throw $e;
            }

            $this->assertAccessible($winner);
            $bill = $winner;
        }

        return $this->find($bill);
    }

    private function insert(BillData $data): Bill
    {
        return DB::transaction(function () use ($data): Bill {
            $this->lockSupplierAndCheckDuplicate($data->supplier_id, $data->document_no, null);

            $bill = new Bill();
            $this->fill($bill, $data);

            $bill->bill_no        = $this->numbers->next(
                DocumentNumberService::TYPE_BILL,
                CarbonImmutable::parse($data->transaction_date),
            );
            $bill->request_id     = $data->request_id;
            $bill->status         = BillStatus::Draft;
            $bill->payment_status = PaymentStatus::Unpaid;
            $bill->paid_amount    = '0.00';
            $bill->currency       = 'LKR';
            $bill->exchange_rate  = 1;
            $bill->created_by     = Auth::id();
            $bill->updated_by     = Auth::id();
            $bill->save();

            $this->writeLines($bill, $data);

            $this->audit->record(Bill::AUDIT_TYPE, $bill->id, 'created', null, $this->snapshot($bill));

            return $bill;
        });
    }

    public function update(Bill $bill, BillData $data): Bill
    {
        $this->assertAccessible($bill);

        $bill = DB::transaction(function () use ($bill, $data): Bill {
            /** @var Bill $locked */
            $locked = Bill::whereKey($bill->id)->lockForUpdate()->firstOrFail();

            $this->assertStatus($locked, BillStatus::Draft, 'edited');
            $this->assertNotStale($locked, $data->updated_at);
            $this->lockSupplierAndCheckDuplicate($data->supplier_id, $data->document_no, $locked->id);

            $before = $this->snapshot($locked);

            $this->fill($locked, $data);
            $locked->updated_by = Auth::id();
            $locked->save();

            $locked->lines()->delete();
            $this->writeLines($locked, $data);

            [$old, $new] = $this->audit->diff($before, $this->snapshot($locked->fresh()));

            if ($new !== []) {
                $this->audit->record(Bill::AUDIT_TYPE, $locked->id, 'updated', $old, $new);
            }

            return $locked;
        });

        return $this->find($bill->fresh());
    }

    /** Drafts only. A confirmed bill is voided, never deleted. */
    public function delete(Bill $bill): void
    {
        $this->assertAccessible($bill);

        DB::transaction(function () use ($bill): void {
            /** @var Bill $locked */
            $locked = Bill::whereKey($bill->id)->lockForUpdate()->firstOrFail();

            $this->assertStatus($locked, BillStatus::Draft, 'deleted');

            $this->audit->record(Bill::AUDIT_TYPE, $locked->id, 'deleted', $this->snapshot($locked), null);

            $locked->delete();
        });
    }

    /** Post the bill to the ledger. */
    public function confirm(Bill $bill): Bill
    {
        $this->assertAccessible($bill);

        DB::transaction(function () use ($bill): void {
            /** @var Bill $locked */
            $locked = Bill::whereKey($bill->id)->lockForUpdate()->firstOrFail();

            $this->assertStatus($locked, BillStatus::Draft, 'confirmed');
            $this->controls->assertNotMaker($locked->created_by, Auth::id());
            $this->controls->assertOpen($locked->transaction_date, 'transaction_date');

            // Re-checked under lock: another bill for the same supplier invoice
            // may have been confirmed since this draft was saved.
            $this->lockSupplierAndCheckDuplicate($locked->supplier_id, $locked->document_no, $locked->id);

            if (Bill::toCents($locked->total_amount) <= 0) {
                throw ValidationException::withMessages(['total_amount' => 'A bill must have an amount.']);
            }

            $this->assertLinesStillBillable($locked);

            $poster = app(JournalPoster::class);

            if (! $poster->acceptsPostings()) {
                throw ValidationException::withMessages([
                    'status' => 'The Finance module is not enabled, so this bill cannot be posted.',
                ]);
            }

            $journalNo = $poster->post(BillJournal::build($locked));

            $locked->forceFill([
                'status'       => BillStatus::Confirmed,
                'journal_no'   => $journalNo,
                'confirmed_at' => now(),
                'confirmed_by' => Auth::id(),
                'updated_by'   => Auth::id(),
            ])->save();

            $this->audit->record(
                Bill::AUDIT_TYPE,
                $locked->id,
                'confirmed',
                ['status' => BillStatus::Draft->value],
                ['status' => BillStatus::Confirmed->value, 'journal_no' => $journalNo],
            );
        });

        return $this->find($bill->fresh());
    }

    /**
     * Reverse a confirmed bill on a chosen date.
     *
     * The reversal must fall in an open period, on or after the bill's own
     * date, and not in the future. JournalPostingService checks the same
     * rules again before it writes.
     */
    public function void(Bill $bill, string $reason, string $reversalDate): Bill
    {
        $this->assertAccessible($bill);

        DB::transaction(function () use ($bill, $reason, $reversalDate): void {
            /** @var Bill $locked */
            $locked = Bill::whereKey($bill->id)->lockForUpdate()->firstOrFail();

            $this->assertStatus($locked, BillStatus::Confirmed, 'voided');

            if (Bill::toCents($locked->paid_amount) > 0) {
                throw ValidationException::withMessages([
                    'status' => 'This bill has payments against it. Reverse the payments first.',
                ]);
            }

            $date = $this->controls->assertReversalDate($reversalDate, $locked->transaction_date, 'bill');

            $reversalNo = app(JournalPoster::class)->reverseDocument(
                PostingRole::SOURCE_BILL,
                (int) $locked->id,
                'Void of ' . $locked->bill_no . ': ' . $reason,
                $date,
            );

            $locked->forceFill([
                'status'              => BillStatus::Voided,
                'voided_at'           => now(),
                'voided_by'           => Auth::id(),
                'void_reason'         => $reason,
                'reversal_date'       => $date->toDateString(),
                'reversal_journal_no' => $reversalNo,
                'updated_by'          => Auth::id(),
            ])->save();

            $this->audit->record(
                Bill::AUDIT_TYPE,
                $locked->id,
                'voided',
                ['status' => BillStatus::Confirmed->value],
                [
                    'status'              => BillStatus::Voided->value,
                    'void_reason'         => $reason,
                    'reversal_date'       => $date->toDateString(),
                    'reversal_journal_no' => $reversalNo,
                ],
            );
        });

        return $this->find($bill->fresh());
    }

    // ── Guards ───────────────────────────────────────────────────────────────

    /** Another company's bill does not exist, as far as this user can tell. */
    public function assertAccessible(Bill $bill): void
    {
        if (! $this->companyAccess->canAccess($this->actor(), $bill->company_id)) {
            throw new NotFoundHttpException();
        }
    }

    private function assertStatus(Bill $bill, BillStatus $required, string $action): void
    {
        if ($bill->status !== $required) {
            throw ValidationException::withMessages([
                'status' => $bill->bill_no . ' is ' . $bill->status->label() . ' and cannot be ' . $action . '.',
            ]);
        }
    }

    /**
     * Optimistic lock: refuse a save built on an older copy of the bill, so
     * two editors cannot silently overwrite each other.
     */
    private function assertNotStale(Bill $bill, ?string $seenUpdatedAt): void
    {
        if ($seenUpdatedAt === null || $bill->updated_at === null) {
            return;
        }

        try {
            $seen = CarbonImmutable::parse($seenUpdatedAt);
        } catch (\Throwable) {
            return;
        }

        if ($seen->getTimestamp() !== $bill->updated_at->getTimestamp()) {
            throw new HttpException(409, 'This bill was changed by someone else. Reload it and try again.');
        }
    }

    /**
     * The same rule BillRequest applied when the draft was saved, re-checked:
     * since then an account may have been deactivated, turned into a Bank or
     * Cash Book, made a party ledger, or moved to another company.
     */
    private function assertLinesStillBillable(Bill $bill): void
    {
        $bill->loadMissing('lines');

        $accounts = LedgerAccount::query()
            ->billable()
            ->whereKey($bill->lines->pluck('ledger_account_id')->all())
            ->get(['id', 'company_id'])
            ->keyBy('id');

        foreach ($bill->lines as $line) {
            $account = $accounts->get($line->ledger_account_id);

            if ($account === null || ($account->company_id !== null && $account->company_id !== $bill->company_id)) {
                throw ValidationException::withMessages([
                    'lines' => 'The account on this bill can no longer be charged (it is inactive, no longer an expense account, '
                        . 'or belongs to another company). Edit the bill and choose another account.',
                ]);
            }
        }
    }

    /**
     * Serialise bill writes per supplier by locking the supplier row, then
     * check for the same supplier invoice. Without the lock two users could
     * each pass the check and both save the same invoice.
     */
    private function lockSupplierAndCheckDuplicate(int $supplierId, string $documentNo, ?int $ignoreId): void
    {
        DB::table('inv_supplier_masters')->where('id', $supplierId)->lockForUpdate()->value('id');

        $duplicate = Bill::query()
            ->where('supplier_id', $supplierId)
            ->where('document_no_normalized', Bill::normalizeDocumentNo($documentNo))
            ->where('status', '!=', BillStatus::Voided->value)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->value('bill_no');

        if ($duplicate !== null) {
            throw ValidationException::withMessages([
                'document_no' => 'This supplier invoice is already entered as ' . $duplicate . '.',
            ]);
        }
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function fill(Bill $bill, BillData $data): void
    {
        $bill->fill([
            'supplier_id'      => $data->supplier_id,
            'transaction_date' => $data->transaction_date,
            'document_no'      => $data->document_no,
            'document_date'    => $data->document_date,
            'due_date'         => $data->due_date,
            'company_id'       => $data->company_id,
            'location_id'      => $data->location_id,
            'description'      => $data->description,
        ]);

        $bill->document_no_normalized = Bill::normalizeDocumentNo($data->document_no);

        $cents = 0;

        foreach ($data->lines as $line) {
            $cents += Bill::toCents($line['amount']);
        }

        $bill->total_amount = Bill::fromCents($cents);
    }

    private function writeLines(Bill $bill, BillData $data): void
    {
        foreach (array_values($data->lines) as $i => $line) {
            $bill->lines()->create([
                'line_no'           => $i + 1,
                'ledger_account_id' => $line['ledger_account_id'],
                'description'       => $line['description'],
                'amount'            => $line['amount'],
            ]);
        }
    }

    /** @return array<string, mixed> What the audit trail records for a bill. */
    private function snapshot(Bill $bill): array
    {
        $bill->loadMissing('lines');

        return [
            'bill_no'          => $bill->bill_no,
            'supplier_id'      => $bill->supplier_id,
            'transaction_date' => $bill->transaction_date?->toDateString(),
            'document_no'      => $bill->document_no,
            'document_date'    => $bill->document_date?->toDateString(),
            'due_date'         => $bill->due_date?->toDateString(),
            'company_id'       => $bill->company_id,
            'location_id'      => $bill->location_id,
            'description'      => $bill->description,
            'total_amount'     => (string) $bill->total_amount,
            'status'           => $bill->status?->value,
            'lines'            => $bill->lines->map(fn ($l): array => [
                'ledger_account_id' => $l->ledger_account_id,
                'amount'            => (string) $l->amount,
                'description'       => $l->description,
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
