<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Models\User;
use App\Services\CompanyAccessService;
use App\Services\ReceivedChequeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Enums\BillStatus;
use Modules\Finance\Enums\CashBookType;
use Modules\Finance\Enums\PaymentStatus;
use Modules\Finance\Models\Bill;
use Modules\Finance\Models\LedgerAccount;

/**
 * Cash, Bank & Cheques on the home dashboard. A live snapshot.
 *
 *   Cash books  — what can be spent now: posted balance less cheques not yet
 *                 banked (FundBalanceService::availableNow, the Pay From figure).
 *   Banks       — posted ledger balance; may be negative when overdrawn.
 *   Cheques     — received cheques sitting in a cash book, not yet on a
 *                 confirmed deposit.
 *   Bills       — confirmed supplier bills not fully paid, by due date.
 *
 * Restricted to the companies the user may see (CompanyAccessService).
 */
class DashboardService
{
    private const BILL_ROWS     = 6;
    private const DUE_SOON_DAYS = 7;

    public function __construct(
        private readonly FundBalanceService $funds,
        private readonly ReceivedChequeRegistry $cheques,
        private readonly CompanyAccessService $companies,
    ) {
    }

    /** @return array<string, mixed> */
    public function cash(?User $user): array
    {
        $allowed = $this->companies->allowedCompanyIds($user);

        $accounts = LedgerAccount::query()
            ->whereNotNull('cash_book_type')
            ->where('is_active', true)
            ->when($allowed !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('company_id', $allowed)->orWhereNull('company_id')))
            ->orderBy('ledger_account_name')
            ->get();

        $available = $this->funds->availableNow($accounts);
        $cheques   = $this->cheques->undepositedTotals(
            $accounts->filter(fn (LedgerAccount $a): bool => (bool) $a->allows_cheque)->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        );

        $rows = $accounts->map(fn (LedgerAccount $a): array => [
            'id'      => (int) $a->id,
            'name'    => $a->ledger_account_name,
            'type'    => $a->cash_book_type === CashBookType::Bank ? 'bank' : 'cash',
            'balance' => (float) ($available[$a->id] ?? 0),
        ])->values();

        return [
            'cash_total'      => round((float) $rows->where('type', 'cash')->sum('balance'), 2),
            'bank_total'      => round((float) $rows->where('type', 'bank')->sum('balance'), 2),
            'cheques_in_hand' => round((float) array_sum($cheques), 2),
            'accounts'        => $rows->all(),
            'bills'           => $this->bills($allowed),
        ];
    }

    /**
     * @param  list<int>|null $allowed
     * @return array<string, mixed>
     */
    private function bills(?array $allowed): array
    {
        $today   = CarbonImmutable::today();
        $soon    = $today->addDays(self::DUE_SOON_DAYS + 1)->toDateString();

        $open = fn () => DB::table('fin_bills as b')
            ->where('b.status', BillStatus::Confirmed->value)
            ->where('b.payment_status', '!=', PaymentStatus::Paid->value)
            ->whereNull('b.deleted_at')
            ->when($allowed !== null, fn ($q) => $q->whereIn('b.company_id', $allowed));

        $balance = 'COALESCE(SUM(b.total_amount - b.paid_amount), 0)';

        $overdue = $open()->where('b.due_date', '<', $today->toDateString())
            ->selectRaw("COUNT(*) as cnt, {$balance} as total")->first();
        // [today, today + 7 days] written half-open: a cast date may carry a time part.
        $dueSoon = $open()->where('b.due_date', '>=', $today->toDateString())->where('b.due_date', '<', $soon)
            ->selectRaw("COUNT(*) as cnt, {$balance} as total")->first();
        $all     = $open()->selectRaw("COUNT(*) as cnt, {$balance} as total")->first();

        $upcoming = $open()
            ->leftJoin('inv_supplier_masters as s', 's.id', '=', 'b.supplier_id')
            ->orderBy('b.due_date')
            ->limit(self::BILL_ROWS)
            ->get(['b.id', 'b.bill_no', 'b.due_date', 'b.total_amount', 'b.paid_amount', 's.supplier_name'])
            ->map(fn ($b): array => [
                'id'       => (int) $b->id,
                'bill_no'  => $b->bill_no,
                'supplier' => $b->supplier_name,
                'due_date' => substr((string) $b->due_date, 0, 10),
                'overdue'  => $b->due_date < $today->toDateString(),
                'balance'  => (float) Bill::fromCents(Bill::toCents($b->total_amount) - Bill::toCents($b->paid_amount)),
            ])
            ->all();

        return [
            'open_count'     => (int) $all->cnt,
            'open_total'     => round((float) $all->total, 2),
            'overdue_count'  => (int) $overdue->cnt,
            'overdue_total'  => round((float) $overdue->total, 2),
            'due_soon_count' => (int) $dueSoon->cnt,
            'due_soon_total' => round((float) $dueSoon->total, 2),
            'upcoming'       => $upcoming,
        ];
    }
}
