<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Contracts\JournalPoster;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ChequeRegistry;
use App\Services\CompanyAccessService;
use App\Support\Journal\PostingRole;
use App\Models\PaymentMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Finance\DTOs\BillPaymentData;
use Modules\Finance\Enums\BillPaymentStatus;
use Modules\Finance\Enums\BillStatus;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Models\Bill;
use Modules\Finance\Models\BillPayment;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Posting\BillPaymentJournal;
use Modules\Finance\Support\PaymentModeRules;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Bill payments: enter, confirm (pay out + post), void (reverse).
 *
 * A draft reserves nothing. Bill balances move only when a payment is
 * confirmed, and confirm re-checks every bill under a row lock, so two drafts
 * for the same bill cannot both be confirmed past its balance.
 *
 * Lock order, everywhere: payment row, then pay-from account row, then bill
 * rows in ascending id. One order means two payments touching the same bills
 * queue rather than deadlock. Bill confirm/void lock the same bill rows, so a
 * bill cannot be voided while a payment against it is being confirmed.
 */
class BillPaymentService
{
    private const EAGER = [
        'supplier:id,supplier_code,supplier_name',
        'company:id,company_name',
        'payFromAccount:id,code,ledger_account_name,cash_book_type',
        'allocations.bill:id,bill_no,document_no,transaction_date,due_date,total_amount,paid_amount,status,payment_status',
    ];

    private const SORTABLE = ['payment_no', 'payment_date', 'total_amount', 'created_at'];

    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly FinanceControlService $controls,
        private readonly CompanyAccessService  $companyAccess,
        private readonly AuditLogger           $audit,
        private readonly ChequeRegistry        $cheques,
    ) {}

    // ── Reading ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $query = BillPayment::query()->with(self::EAGER);

        $this->companyAccess->scope($query, $this->actor(), 'company_id', includeNull: false);

        if (! empty($filters['search'])) {
            $term = '%' . addcslashes((string) $filters['search'], '%_\\') . '%';
            $query->where(fn ($q) => $q
                ->where('payment_no', 'like', $term)
                ->orWhere('reference_no', 'like', $term)
                ->orWhere('cheque_no', 'like', $term)
                ->orWhere('description', 'like', $term)
                ->orWhereHas('supplier', fn ($s) => $s->where('supplier_name', 'like', $term)));
        }

        if (! empty($filters['status']) && BillPaymentStatus::tryFrom((string) $filters['status']) !== null) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['payment_mode_id']) && is_numeric($filters['payment_mode_id'])) {
            $query->where('payment_mode_id', (int) $filters['payment_mode_id']);
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        if (! empty($filters['pay_from_account_id'])) {
            $query->where('pay_from_account_id', (int) $filters['pay_from_account_id']);
        }

        if (! empty($filters['date_from']) && $this->isDate($filters['date_from'])) {
            $query->whereDate('payment_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to']) && $this->isDate($filters['date_to'])) {
            $query->whereDate('payment_date', '<=', $filters['date_to']);
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true) ? $filters['sort'] : 'payment_date';
        $dir  = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $dir)->orderByDesc('id')->paginate($perPage);
    }

    public function find(BillPayment $payment): BillPayment
    {
        $this->assertAccessible($payment);

        return $payment->load([...self::EAGER, 'attachments', 'creator:id,name']);
    }

    /** @return Collection<int, AuditLog> */
    public function history(BillPayment $payment): Collection
    {
        $this->assertAccessible($payment);

        return AuditLog::with('user:id,name')
            ->where('auditable_type', BillPayment::AUDIT_TYPE)
            ->where('auditable_id', $payment->id)
            ->orderByDesc('id')
            ->get();
    }

    public function previewNextNo(?string $date = null): string
    {
        return $this->numbers->preview(
            DocumentNumberService::TYPE_BILL_PAYMENT,
            $date !== null && $this->isDate($date) ? CarbonImmutable::parse($date) : CarbonImmutable::today(),
        );
    }

    /**
     * The supplier's confirmed bills that still have something owing, oldest
     * due first -- the rows the payment form offers.
     *
     * @return list<array<string, mixed>>
     */
    public function openBills(int $supplierId, int $companyId): array
    {
        if (! $this->companyAccess->canAccess($this->actor(), $companyId)) {
            throw new NotFoundHttpException();
        }

        $today = CarbonImmutable::today();

        return Bill::query()
            ->with('lines.ledgerAccount:id,code,ledger_account_name')
            ->where('supplier_id', $supplierId)
            ->where('company_id', $companyId)
            ->where('status', BillStatus::Confirmed->value)
            ->where('payment_status', '!=', PaymentStatus::Paid->value)
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->filter(fn (Bill $b): bool => Bill::toCents($b->balance()) > 0)
            ->map(function (Bill $b) use ($today): array {
                $account = $b->lines->first()?->ledgerAccount;
                $due     = CarbonImmutable::parse($b->due_date);

                return [
                    'bill_id'          => $b->id,
                    'bill_no'          => $b->bill_no,
                    'document_no'      => $b->document_no,
                    'transaction_date' => $b->transaction_date?->toDateString(),
                    'due_date'         => $b->due_date?->toDateString(),
                    // Positive = days past due; negative = days still to go.
                    'days_overdue'     => (int) $due->diffInDays($today, false),
                    'account'          => $account === null ? null : $account->code . ' - ' . $account->ledger_account_name,
                    'description'      => $b->description,
                    'total_amount'     => (string) $b->total_amount,
                    'paid_amount'      => (string) $b->paid_amount,
                    'balance'          => $b->balance(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The non-voided payment that already used this cheque from this book,
     * if any -- another bill payment, or an Inventory Supplier Payment drawn
     * on the same bank account. An idempotent retry of the same create is not
     * a clash.
     */
    public function chequeAlreadyUsed(int $accountId, string $chequeNo, ?int $ignoreId, ?string $requestId = null): ?string
    {
        $own = BillPayment::query()
            ->where('pay_from_account_id', $accountId)
            ->where('cheque_no', BillPayment::normalizeChequeNo($chequeNo))
            ->where('status', '!=', BillPaymentStatus::Voided->value)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->when($requestId !== null && $requestId !== '', fn ($q) => $q->where(
                fn ($w) => $w->whereNull('request_id')->orWhere('request_id', '!=', $requestId),
            ))
            ->value('payment_no');

        return $own ?? $this->cheques->usedElsewhere($accountId, $chequeNo, ChequeRegistry::SOURCE_BILL_PAYMENT);
    }

    // ── Writing ──────────────────────────────────────────────────────────────

    public function create(BillPaymentData $data): BillPayment
    {
        // Idempotent create: a retried request gets the payment already made.
        if ($data->request_id !== null) {
            $existing = BillPayment::withTrashed()->where('request_id', $data->request_id)->first();

            if ($existing !== null) {
                $this->assertAccessible($existing);

                return $this->find($existing);
            }
        }

        try {
            $payment = $this->insert($data);
        } catch (UniqueConstraintViolationException $e) {
            // Two copies of the same submit raced past the check above; the
            // request_id unique index let one in. Answer with that one.
            $winner = $data->request_id === null
                ? null
                : BillPayment::withTrashed()->where('request_id', $data->request_id)->first();

            if ($winner === null) {
                throw $e;
            }

            $this->assertAccessible($winner);
            $payment = $winner;
        }

        return $this->find($payment);
    }

    private function insert(BillPaymentData $data): BillPayment
    {
        return DB::transaction(function () use ($data): BillPayment {
            $payment = new BillPayment();
            $this->fill($payment, $data);

            $payment->payment_no    = $this->numbers->next(
                DocumentNumberService::TYPE_BILL_PAYMENT,
                CarbonImmutable::parse($data->payment_date),
            );
            $payment->request_id    = $data->request_id;
            $payment->status        = BillPaymentStatus::Draft;
            $payment->currency      = 'LKR';
            $payment->exchange_rate = 1;
            $payment->created_by    = Auth::id();
            $payment->updated_by    = Auth::id();
            $payment->save();

            $this->writeAllocations($payment, $data);

            $this->audit->record(BillPayment::AUDIT_TYPE, $payment->id, 'created', null, $this->snapshot($payment));

            return $payment;
        });
    }

    public function update(BillPayment $payment, BillPaymentData $data): BillPayment
    {
        $this->assertAccessible($payment);

        DB::transaction(function () use ($payment, $data): void {
            /** @var BillPayment $locked */
            $locked = BillPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $this->assertStatus($locked, BillPaymentStatus::Draft, 'edited');
            $this->assertNotStale($locked, $data->updated_at);

            $before = $this->snapshot($locked);

            $this->fill($locked, $data);
            $locked->updated_by = Auth::id();
            $locked->save();

            $locked->allocations()->delete();
            $this->writeAllocations($locked, $data);

            [$old, $new] = $this->audit->diff($before, $this->snapshot($locked->fresh()));

            if ($new !== []) {
                $this->audit->record(BillPayment::AUDIT_TYPE, $locked->id, 'updated', $old, $new);
            }
        });

        return $this->find($payment->fresh());
    }

    /** Drafts only. A confirmed payment is voided, never deleted. */
    public function delete(BillPayment $payment): void
    {
        $this->assertAccessible($payment);

        DB::transaction(function () use ($payment): void {
            /** @var BillPayment $locked */
            $locked = BillPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $this->assertStatus($locked, BillPaymentStatus::Draft, 'deleted');

            $this->audit->record(BillPayment::AUDIT_TYPE, $locked->id, 'deleted', $this->snapshot($locked), null);

            $locked->delete();
        });
    }

    /** Pay the bills: post the journal and reduce each bill's balance. */
    public function confirm(BillPayment $payment): BillPayment
    {
        $this->assertAccessible($payment);

        DB::transaction(function () use ($payment): void {
            /** @var BillPayment $locked */
            $locked = BillPayment::with('allocations')->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $this->assertStatus($locked, BillPaymentStatus::Draft, 'confirmed');
            $this->controls->assertNotMaker($locked->created_by, Auth::id());
            $this->controls->assertOpen($locked->payment_date, 'payment_date');

            $this->assertPayFromStillValid($locked);

            if ($locked->cheque_no !== null) {
                $clash = $this->chequeAlreadyUsed($locked->pay_from_account_id, $locked->cheque_no, $locked->id);

                if ($clash !== null) {
                    throw ValidationException::withMessages([
                        'cheque_no' => 'Cheque ' . $locked->cheque_no . ' is already used on ' . $clash . '.',
                    ]);
                }
            }

            $bills = $this->lockBills($locked);

            foreach ($locked->allocations as $allocation) {
                /** @var Bill|null $bill */
                $bill = $bills->get($allocation->bill_id);

                if ($bill === null
                    || $bill->status !== BillStatus::Confirmed
                    || $bill->supplier_id !== $locked->supplier_id
                    || $bill->company_id !== $locked->company_id) {
                    throw ValidationException::withMessages([
                        'allocations' => ($bill?->bill_no ?? 'A bill') . ' can no longer be paid by this payment.',
                    ]);
                }

                if ($locked->payment_date->lt($bill->transaction_date)) {
                    throw ValidationException::withMessages([
                        'allocations' => $bill->bill_no . ' is dated ' . $bill->transaction_date->format('d-m-Y')
                            . '. The payment date cannot be before the bill date.',
                    ]);
                }

                if (Bill::toCents($allocation->amount) > Bill::toCents($bill->balance())) {
                    throw ValidationException::withMessages([
                        'allocations' => $bill->bill_no . ' now has only ' . number_format((float) $bill->balance(), 2)
                            . ' owing. Another payment was confirmed against it; edit this payment and try again.',
                    ]);
                }
            }

            $poster = app(JournalPoster::class);

            if (! $poster->acceptsPostings()) {
                throw ValidationException::withMessages([
                    'status' => 'The Finance module is not enabled, so this payment cannot be posted.',
                ]);
            }

            $journalNo = $poster->post(BillPaymentJournal::build($locked));

            foreach ($locked->allocations as $allocation) {
                $bill = $bills->get($allocation->bill_id);

                $allocation->forceFill(['balance_before' => $bill->balance()])->save();

                $this->applyToBill($bill, Bill::toCents($allocation->amount), $locked, 'payment_applied');
            }

            $locked->forceFill([
                'status'       => BillPaymentStatus::Confirmed,
                'journal_no'   => $journalNo,
                'confirmed_at' => now(),
                'confirmed_by' => Auth::id(),
                'updated_by'   => Auth::id(),
            ])->save();

            $this->audit->record(
                BillPayment::AUDIT_TYPE,
                $locked->id,
                'confirmed',
                ['status' => BillPaymentStatus::Draft->value],
                ['status' => BillPaymentStatus::Confirmed->value, 'journal_no' => $journalNo],
            );
        });

        return $this->find($payment->fresh());
    }

    /**
     * Reverse a confirmed payment on a chosen date and give the bills their
     * balances back. The reversal date follows the rule every Finance void
     * follows (FinanceControlService::assertReversalDate).
     */
    public function void(BillPayment $payment, string $reason, string $reversalDate): BillPayment
    {
        $this->assertAccessible($payment);

        DB::transaction(function () use ($payment, $reason, $reversalDate): void {
            /** @var BillPayment $locked */
            $locked = BillPayment::with('allocations')->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $this->assertStatus($locked, BillPaymentStatus::Confirmed, 'voided');

            $date = $this->controls->assertReversalDate($reversalDate, $locked->payment_date, 'payment');

            $bills = $this->lockBills($locked);

            $reversalNo = app(JournalPoster::class)->reverseDocument(
                PostingRole::SOURCE_BILL_PAYMENT,
                (int) $locked->id,
                'Void of ' . $locked->payment_no . ': ' . $reason,
                $date,
            );

            foreach ($locked->allocations as $allocation) {
                $bill = $bills->get($allocation->bill_id);

                if ($bill !== null) {
                    $this->applyToBill($bill, -Bill::toCents($allocation->amount), $locked, 'payment_reversed');
                }
            }

            $locked->forceFill([
                'status'              => BillPaymentStatus::Voided,
                'voided_at'           => now(),
                'voided_by'           => Auth::id(),
                'void_reason'         => $reason,
                'reversal_date'       => $date->toDateString(),
                'reversal_journal_no' => $reversalNo,
                'updated_by'          => Auth::id(),
            ])->save();

            $this->audit->record(
                BillPayment::AUDIT_TYPE,
                $locked->id,
                'voided',
                ['status' => BillPaymentStatus::Confirmed->value],
                [
                    'status'              => BillPaymentStatus::Voided->value,
                    'void_reason'         => $reason,
                    'reversal_date'       => $date->toDateString(),
                    'reversal_journal_no' => $reversalNo,
                ],
            );
        });

        return $this->find($payment->fresh());
    }

    // ── Guards ───────────────────────────────────────────────────────────────

    /** Another company's payment does not exist, as far as this user can tell. */
    public function assertAccessible(BillPayment $payment): void
    {
        if (! $this->companyAccess->canAccess($this->actor(), $payment->company_id)) {
            throw new NotFoundHttpException();
        }
    }

    private function assertStatus(BillPayment $payment, BillPaymentStatus $required, string $action): void
    {
        if ($payment->status !== $required) {
            throw ValidationException::withMessages([
                'status' => $payment->payment_no . ' is ' . $payment->status->label() . ' and cannot be ' . $action . '.',
            ]);
        }
    }

    private function assertNotStale(BillPayment $payment, ?string $seenUpdatedAt): void
    {
        if ($seenUpdatedAt === null || $payment->updated_at === null) {
            return;
        }

        try {
            $seen = CarbonImmutable::parse($seenUpdatedAt);
        } catch (\Throwable) {
            return;
        }

        if ($seen->getTimestamp() !== $payment->updated_at->getTimestamp()) {
            throw new HttpException(409, 'This payment was changed by someone else. Reload it and try again.');
        }
    }

    /**
     * The pay-from account is locked (serialising cheque numbers per account)
     * and re-checked, with the payment mode: either may have been deactivated
     * or changed since the draft.
     */
    private function assertPayFromStillValid(BillPayment $payment): void
    {
        $mode = PaymentMode::query()->find($payment->payment_mode_id);

        if ($mode === null || ! PaymentModeRules::isUsable($mode)) {
            throw ValidationException::withMessages([
                'payment_mode_id' => 'The payment mode ' . $payment->payment_mode_name
                    . ' is no longer active. Edit the payment and choose another.',
            ]);
        }

        // A client-made mode's Type (or its "requires reference" flag) may have
        // changed since the draft, e.g. Card -> Bank Transfer.
        if (PaymentModeRules::needsReference($mode) && trim((string) $payment->reference_no) === '') {
            throw ValidationException::withMessages([
                'reference_no' => 'The payment mode ' . $mode->payment_mode_name
                    . ' now needs a Reference No. Edit the payment and enter it.',
            ]);
        }

        /** @var LedgerAccount|null $account */
        $account = LedgerAccount::query()
            ->payFrom()
            ->whereKey($payment->pay_from_account_id)
            ->lockForUpdate()
            ->first();

        if ($account === null
            || ($account->company_id !== null && $account->company_id !== $payment->company_id)
            || ! PaymentModeRules::accountSuits($mode, $account)) {
            throw ValidationException::withMessages([
                'pay_from_account_id' => 'The pay-from account is no longer valid for a '
                    . $mode->payment_mode_name . ' payment. Edit the payment and choose another.',
            ]);
        }
    }

    /** @return \Illuminate\Support\Collection<int, Bill> keyed by id, locked in ascending id order. */
    private function lockBills(BillPayment $payment): \Illuminate\Support\Collection
    {
        return Bill::query()
            ->whereIn('id', $payment->allocations->pluck('bill_id')->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /** Move a bill's paid amount by $cents and restate its payment status. */
    private function applyToBill(Bill $bill, int $cents, BillPayment $payment, string $event): void
    {
        $oldPaid   = (string) $bill->paid_amount;
        $oldStatus = $bill->payment_status?->value;

        $paid  = max(0, Bill::toCents($bill->paid_amount) + $cents);
        $total = Bill::toCents($bill->total_amount);

        $bill->forceFill([
            'paid_amount'    => Bill::fromCents($paid),
            'payment_status' => match (true) {
                $paid <= 0      => PaymentStatus::Unpaid,
                $paid >= $total => PaymentStatus::Paid,
                default         => PaymentStatus::PartlyPaid,
            },
        ])->save();

        $this->audit->record(
            Bill::AUDIT_TYPE,
            $bill->id,
            $event,
            ['paid_amount' => $oldPaid, 'payment_status' => $oldStatus],
            [
                'paid_amount'    => (string) $bill->paid_amount,
                'payment_status' => $bill->payment_status->value,
                'payment_no'     => $payment->payment_no,
            ],
        );
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function fill(BillPayment $payment, BillPaymentData $data): void
    {
        $payment->fill([
            'supplier_id'         => $data->supplier_id,
            'company_id'          => $data->company_id,
            'payment_date'        => $data->payment_date,
            'reference_no'        => $data->reference_no,
            'pay_from_account_id' => $data->pay_from_account_id,
            'payment_mode_id'     => $data->payment_mode_id,
            'cheque_no'           => BillPayment::normalizeChequeNo($data->cheque_no),
            'cheque_date'         => $data->cheque_date,
            'description'         => $data->description,
        ]);

        // Snapshot the mode as it is now; the request already proved it usable.
        $mode = PaymentMode::query()->findOrFail($data->payment_mode_id);
        $payment->forceFill([
            'payment_mode_name' => $mode->payment_mode_name,
            'payment_mode_code' => $mode->code,
        ]);

        $cents = 0;

        foreach ($data->allocations as $row) {
            $cents += Bill::toCents($row['amount']);
        }

        $payment->total_amount = Bill::fromCents($cents);
    }

    private function writeAllocations(BillPayment $payment, BillPaymentData $data): void
    {
        foreach (array_values($data->allocations) as $i => $row) {
            $payment->allocations()->create([
                'line_no' => $i + 1,
                'bill_id' => $row['bill_id'],
                'amount'  => $row['amount'],
            ]);
        }
    }

    /** @return array<string, mixed> What the audit trail records for a payment. */
    private function snapshot(BillPayment $payment): array
    {
        $payment->load('allocations');

        return [
            'payment_no'          => $payment->payment_no,
            'supplier_id'         => $payment->supplier_id,
            'company_id'          => $payment->company_id,
            'payment_date'        => $payment->payment_date?->toDateString(),
            'reference_no'        => $payment->reference_no,
            'pay_from_account_id' => $payment->pay_from_account_id,
            'payment_mode'        => $payment->payment_mode_name,
            'cheque_no'           => $payment->cheque_no,
            'cheque_date'         => $payment->cheque_date?->toDateString(),
            'description'         => $payment->description,
            'total_amount'        => (string) $payment->total_amount,
            'status'              => $payment->status?->value,
            'allocations'         => $payment->allocations->map(fn ($a): array => [
                'bill_id' => $a->bill_id,
                'amount'  => (string) $a->amount,
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
