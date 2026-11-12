<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Models\Company;
use App\Models\User;
use App\Services\ChequeStatusRegistry;
use App\Services\CompanyAccessService;
use App\Support\Journal\PostingRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Modules\Finance\Models\BillPayment;
use Modules\Finance\Models\LedgerAccount;

/**
 * Uncleared Cheques: every cheque on a bank account that the bank had not
 * cleared at the end of a chosen day.
 *
 *   Issued, not presented   -- a cheque we wrote (supplier payment, bill
 *                              payment) that the bank has not paid yet
 *   Deposited, not cleared  -- a customer cheque deposited (or received
 *                              straight into the bank) not yet credited
 *
 * "Cleared" means realized or returned on a reconciliation that is posted,
 * with a statement date up to the chosen day -- the rule the reconciliation
 * screen itself uses (BankReconciliationService::unclearedAsAt), so the two
 * can never disagree. An old date gives the list as it stood then.
 *
 * Only ledger lines carrying a cheque instrument are listed. Bank-to-bank
 * transfers, charges and journal vouchers have no cheque number, and deposit
 * slips posted before per-cheque lines existed carry one lump line; those
 * stay on the reconciliation screen only.
 */
class UnclearedChequesReportService
{
    public const TYPE_ALL      = 'all';
    public const TYPE_ISSUED   = 'issued';
    public const TYPE_RECEIVED = 'received';

    /** Rows one report may hold; past this the user narrows the filters. */
    public const MAX_ROWS = 5000;

    /** A cheque older than this (by its cheque date) can no longer be paid. */
    private const STALE_MONTHS = 6;

    public function __construct(
        private readonly BankReconciliationService $reconciliations,
        private readonly CompanyAccessService      $companyAccess,
        private readonly ChequeStatusRegistry      $cheques,
    ) {}

    /**
     * @param  array{as_at:string, company_id?:?int, bank_account_id?:?int, type?:?string, search?:?string} $filters
     * @return array<string, mixed>
     */
    public function build(array $filters): array
    {
        $asAt  = CarbonImmutable::parse($filters['as_at'])->startOfDay();
        $type  = $filters['type'] ?? self::TYPE_ALL;
        $banks = $this->banks($filters);

        $raw       = $this->reconciliations->unclearedAsAt($banks->keys()->all(), $asAt->toDateString(), 'cheque', self::MAX_ROWS);
        $truncated = $raw->count() > self::MAX_ROWS;
        $raw       = $raw->take(self::MAX_ROWS);

        $rows = $this->present($raw, $banks, $asAt);

        if ($type !== self::TYPE_ALL) {
            $rows = $rows->where('type', $type)->values();
        }

        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));

        if ($search !== '') {
            $rows = $rows->filter(fn (array $r): bool => collect([$r['cheque_no'], $r['party_name'], $r['document_no'], $r['journal_no']])
                ->contains(fn ($v): bool => $v !== null && str_contains(mb_strtolower((string) $v), $search)))
                ->values();
        }

        return [
            'header'    => $this->header($filters, $asAt, $type, $banks),
            'rows'      => $rows->all(),
            'banks'     => $this->bankTotals($rows, $banks),
            'summary'   => $this->totals($rows),
            'truncated' => $truncated,
        ];
    }

    /**
     * Bank accounts this user may see, for the filter.
     *
     * @return list<array{id:int, code:?string, name:string, company_id:?int, bank_name:?string, bank_account_no:?string}>
     */
    public function bankAccountOptions(): array
    {
        return $this->banks([])->map(fn (LedgerAccount $a): array => [
            'id'              => $a->id,
            'code'            => $a->code,
            'name'            => $a->ledger_account_name,
            'company_id'      => $a->company_id,
            'bank_name'       => $a->bank?->bank_name,
            'bank_account_no' => $a->bank_account_no,
        ])->values()->all();
    }

    /** @return list<array{id:int, name:string}> */
    public function companyOptions(): array
    {
        $query = Company::query()->orderBy('company_name');
        $this->companyAccess->scope($query, $this->actor(), 'id', includeNull: false);

        return $query->get(['id', 'company_name'])
            ->map(fn (Company $c): array => ['id' => $c->id, 'name' => $c->company_name])
            ->all();
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Active bank accounts of the user's companies, narrowed by the filters.
     *
     * @param  array<string, mixed> $filters
     * @return Collection<int, LedgerAccount> keyed by id
     */
    private function banks(array $filters): Collection
    {
        $query = LedgerAccount::query()
            ->bankAccounts()
            ->with('bank:id,bank_name')
            ->orderBy('code');

        $this->companyAccess->scope($query, $this->actor());

        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        if (! empty($filters['bank_account_id'])) {
            $query->whereKey((int) $filters['bank_account_id']);
        }

        return $query->get(['id', 'code', 'ledger_account_name', 'company_id', 'bank_id', 'bank_account_no'])->keyBy('id');
    }

    /**
     * @param  Collection<int, object>        $raw
     * @param  Collection<int, LedgerAccount> $banks
     * @return Collection<int, array<string, mixed>>
     */
    private function present(Collection $raw, Collection $banks, CarbonImmutable $asAt): Collection
    {
        $details   = $this->details($raw);
        $staleFrom = $asAt->subMonthsNoOverflow(self::STALE_MONTHS);

        return $raw->map(function (object $r) use ($banks, $details, $asAt, $staleFrom): array {
            $issued      = (float) $r->credit > 0;
            $bank        = $banks->get((int) $r->ledger_account_id);
            $detail      = $details[$r->source_type . ':' . $r->source_id] ?? [];
            $journalDate = CarbonImmutable::parse(substr((string) $r->journal_date, 0, 10));
            $chequeDate  = $r->instrument_date === null ? null : CarbonImmutable::parse(substr((string) $r->instrument_date, 0, 10));

            return [
                'journal_line_id' => (int) $r->id,
                'bank_account_id' => (int) $r->ledger_account_id,
                'bank_account'    => $bank === null ? null : trim($bank->code . ' - ' . $bank->ledger_account_name, ' -'),
                'type'            => $issued ? self::TYPE_ISSUED : self::TYPE_RECEIVED,
                'cheque_no'       => $r->instrument_no,
                'cheque_date'     => $chequeDate?->toDateString(),
                'journal_no'      => $r->journal_no,
                'journal_date'    => $journalDate->toDateString(),
                'document_no'     => $detail['document_no'] ?? null,
                'party_name'      => $detail['party_name'] ?? null,
                'drawee_bank'     => $detail['drawee_bank'] ?? null,
                'description'     => $r->description ?: $r->narration,
                'amount'          => number_format($issued ? (float) $r->credit : (float) $r->debit, 2, '.', ''),
                // Since it went through the books: issued, or deposited.
                'days_outstanding' => (int) $journalDate->diffInDays($asAt),
                'is_stale'        => ($chequeDate ?? $journalDate)->lt($staleFrom),
                'is_post_dated'   => $chequeDate !== null && $chequeDate->gt($asAt),
            ];
        })->values();
    }

    /**
     * Document and party per ledger line, keyed "source_type:source_id".
     *
     * @param  Collection<int, object> $raw
     * @return array<string, array<string, ?string>>
     */
    private function details(Collection $raw): array
    {
        $result = [];

        foreach ([PostingRole::SOURCE_CUSTOMER_RECEIPT_SETTLEMENT, PostingRole::SOURCE_SUPPLIER_PAYMENT_SETTLEMENT] as $type) {
            $ids = $this->sourceIds($raw, $type);

            foreach ($this->cheques->detailsFor($type, $ids) as $id => $detail) {
                $result[$type . ':' . $id] = $detail;
            }
        }

        $billPaymentIds = $this->sourceIds($raw, PostingRole::SOURCE_BILL_PAYMENT);

        if ($billPaymentIds !== []) {
            BillPayment::query()
                ->with('supplier:id,supplier_name')
                ->whereIn('id', $billPaymentIds)
                ->get(['id', 'payment_no', 'payment_date', 'supplier_id'])
                ->each(function (BillPayment $p) use (&$result): void {
                    $result[PostingRole::SOURCE_BILL_PAYMENT . ':' . $p->id] = [
                        'document_no'   => $p->payment_no,
                        'document_date' => $p->payment_date?->toDateString(),
                        'party_name'    => $p->supplier?->supplier_name,
                        'drawee_bank'   => null,
                    ];
                });
        }

        return $result;
    }

    /**
     * @param  Collection<int, object> $raw
     * @return list<int>
     */
    private function sourceIds(Collection $raw, string $type): array
    {
        return $raw->where('source_type', $type)
            ->pluck('source_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * One row per bank account that has anything outstanding.
     *
     * @param  Collection<int, array<string, mixed>> $rows
     * @param  Collection<int, LedgerAccount>        $banks
     * @return list<array<string, mixed>>
     */
    private function bankTotals(Collection $rows, Collection $banks): array
    {
        return $rows->groupBy('bank_account_id')
            ->map(function (Collection $group, int|string $bankId) use ($banks): array {
                $bank = $banks->get((int) $bankId);

                return [
                    'bank_account_id' => (int) $bankId,
                    'bank_account'    => $bank === null ? null : trim($bank->code . ' - ' . $bank->ledger_account_name, ' -'),
                    'bank_name'       => $bank?->bank?->bank_name,
                    'bank_account_no' => $bank?->bank_account_no,
                    ...$this->totals($group),
                ];
            })
            ->sortBy('bank_account')
            ->values()
            ->all();
    }

    /**
     * Totals in integer cents, so many lines never drift.
     *
     * @param  Collection<int, array<string, mixed>> $rows
     * @return array{issued_count:int, issued_total:string, received_count:int, received_total:string, stale_count:int}
     */
    private function totals(Collection $rows): array
    {
        $cents = fn (Collection $c): int => (int) $c->sum(fn (array $r): int => (int) round((float) $r['amount'] * 100));

        $issued   = $rows->where('type', self::TYPE_ISSUED);
        $received = $rows->where('type', self::TYPE_RECEIVED);

        return [
            'issued_count'   => $issued->count(),
            'issued_total'   => number_format($cents($issued) / 100, 2, '.', ''),
            'received_count' => $received->count(),
            'received_total' => number_format($cents($received) / 100, 2, '.', ''),
            'stale_count'    => $rows->where('is_stale', true)->count(),
        ];
    }

    /**
     * @param  array<string, mixed>           $filters
     * @param  Collection<int, LedgerAccount> $banks
     * @return array<string, mixed>
     */
    private function header(array $filters, CarbonImmutable $asAt, string $type, Collection $banks): array
    {
        $company = ! empty($filters['company_id']) ? Company::query()->find((int) $filters['company_id']) : null;
        $bank    = ! empty($filters['bank_account_id']) ? $banks->get((int) $filters['bank_account_id']) : null;

        return [
            'company_name'  => $company?->company_name ?? config('app.name'),
            'company_address' => $company === null ? null : collect([
                $company->street_address,
                collect([$company->city, $company->state, $company->postal_zip_code])->filter()->implode(', '),
                $company->country,
            ])->filter()->implode(', '),
            'as_at'         => $asAt->toDateString(),
            'type'          => $type,
            'type_label'    => match ($type) {
                self::TYPE_ISSUED   => 'Issued, not presented',
                self::TYPE_RECEIVED => 'Deposited, not cleared',
                default             => 'All cheques',
            },
            'bank_account'  => $bank === null ? 'All bank accounts' : trim($bank->code . ' - ' . $bank->ledger_account_name, ' -'),
            'search'        => trim((string) ($filters['search'] ?? '')) ?: null,
            'stale_months'  => self::STALE_MONTHS,
            'generated_by'  => $this->actor()?->name,
            'generated_at'  => now()->format('d-m-Y H:i'),
        ];
    }

    private function actor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
