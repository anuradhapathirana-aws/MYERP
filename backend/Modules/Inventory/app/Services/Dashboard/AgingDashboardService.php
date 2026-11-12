<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\GrnStatus;
use Modules\Inventory\Enums\InvoiceStatus;
use Modules\Inventory\Services\GrnBalanceService;
use Modules\Inventory\Services\InvoiceBalanceService;

/**
 * What customers owe us and what we owe suppliers, aged. A live snapshot, not
 * period-bound: a balance is always "as of today".
 *
 * Balances come from the one canonical formula of each side
 * (InvoiceBalanceService / GrnBalanceService), so the totals agree with the
 * Outstanding Summary report and the receipt/payment pickers.
 *
 *   Receivables age from the invoice due date (days past due; not yet due = Current).
 *   Payables age from the GRN date plus its payment terms (Net 30 = 30 days,
 *               Immediate / Cash on Delivery = 0), else the supplier's credit period.
 *
 * A document with no due date at all is never called overdue: it goes in its
 * own "No due date" bucket — the same as the Outstanding Summary report, which
 * shows no days overdue for it.
 */
class AgingDashboardService
{
    private const CHUNK  = 500;
    private const TOP_N  = 5;
    private const BUCKETS = [
        'current' => 'Current',
        '1_30'    => '1–30 days',
        '31_60'   => '31–60 days',
        '61_90'   => '61–90 days',
        'over_90' => '90+ days',
        'no_due'  => 'No due date',
    ];

    public function __construct(
        private readonly InvoiceBalanceService $invoiceBalances,
        private readonly GrnBalanceService $grnBalances,
    ) {
    }

    /** @return array<string, mixed> */
    public function receivables(): array
    {
        $today   = CarbonImmutable::today();
        $buckets = array_fill_keys(array_keys(self::BUCKETS), 0.0);
        $parties = [];
        $total   = 0.0;
        $overdue = 0.0;
        $count   = 0;

        DB::table('inv_invoices as i')
            ->join('inv_customer_masters as cu', 'cu.id', '=', 'i.customer_id')
            ->where('i.status', InvoiceStatus::Issued->value)
            ->whereNull('i.deleted_at')
            ->select(['i.id', 'i.customer_id', 'cu.customer_name', 'i.invoice_date', 'i.due_date', 'i.grand_total'])
            ->orderBy('i.id')
            ->chunk(self::CHUNK, function ($invoices) use ($today, &$buckets, &$parties, &$total, &$overdue, &$count): void {
                $ids      = $invoices->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $received = $this->invoiceBalances->receivedFor($ids);
                $returned = $this->invoiceBalances->returnedFor($ids);

                foreach ($invoices as $inv) {
                    $balance = (float) $inv->grand_total - ($received[$inv->id] ?? 0.0) - ($returned[$inv->id] ?? 0.0);

                    if ($balance <= 0.01) {
                        continue;
                    }

                    [$key, $days] = self::place($inv->due_date ? CarbonImmutable::parse($inv->due_date) : null, $today);

                    $buckets[$key] += $balance;
                    $total         += $balance;
                    $overdue       += $days > 0 ? $balance : 0.0;
                    $count++;

                    $parties[$inv->customer_id] ??= ['id' => (int) $inv->customer_id, 'name' => (string) $inv->customer_name, 'amount' => 0.0];
                    $parties[$inv->customer_id]['amount'] += $balance;
                }
            });

        return $this->shape($total, $overdue, $count, $buckets, $parties);
    }

    /** @return array<string, mixed> */
    public function payables(): array
    {
        $today   = CarbonImmutable::today();
        $buckets = array_fill_keys(array_keys(self::BUCKETS), 0.0);
        $parties = [];
        $total   = 0.0;
        $overdue = 0.0;
        $count   = 0;

        DB::table('inv_goods_received_notes as g')
            ->leftJoin('inv_supplier_masters as s', 's.id', '=', 'g.supplier_id')
            ->where('g.status', GrnStatus::Confirmed->value)
            ->whereNull('g.deleted_at')
            ->where('g.total_amount', '>', 0)
            ->select(['g.id', 'g.supplier_id', 's.supplier_name', 's.credit_period', 'g.payment_terms', 'g.grn_date', 'g.total_amount'])
            ->orderBy('g.id')
            ->chunk(self::CHUNK, function ($grns) use ($today, &$buckets, &$parties, &$total, &$overdue, &$count): void {
                $outstanding = $this->grnBalances->outstandingFor(
                    $grns->mapWithKeys(fn ($g): array => [(int) $g->id => $g->total_amount])->all(),
                );

                foreach ($grns as $grn) {
                    $balance = $outstanding[(int) $grn->id] ?? 0.0;

                    if ($balance <= 0.01) {
                        continue;
                    }

                    $termDays     = self::termDays($grn->payment_terms, $grn->credit_period);
                    [$key, $days] = self::place(
                        $termDays === null ? null : CarbonImmutable::parse($grn->grn_date)->addDays($termDays),
                        $today,
                    );

                    $buckets[$key] += $balance;
                    $total         += $balance;
                    $overdue       += $days > 0 ? $balance : 0.0;
                    $count++;

                    $sid = (int) ($grn->supplier_id ?? 0);
                    $parties[$sid] ??= ['id' => $sid, 'name' => (string) ($grn->supplier_name ?? 'Unknown supplier'), 'amount' => 0.0];
                    $parties[$sid]['amount'] += $balance;
                }
            });

        return $this->shape($total, $overdue, $count, $buckets, $parties);
    }

    /**
     * Credit days for a GRN: its own payment terms first (picked on the GRN form),
     * then the supplier's credit period. Null when neither is known.
     */
    private static function termDays(?string $paymentTerms, mixed $creditPeriod): ?int
    {
        $terms = strtolower(trim((string) $paymentTerms));

        if (in_array($terms, ['immediate', 'cod'], true)) {
            return 0;
        }

        if (preg_match('/^net_(\d+)$/', $terms, $m) === 1) {
            return (int) $m[1];
        }

        return $creditPeriod !== null ? (int) $creditPeriod : null;
    }

    /**
     * Bucket and days past due for a due date; no due date → 'no_due', never overdue.
     *
     * @return array{0: string, 1: int}
     */
    private static function place(?CarbonImmutable $due, CarbonImmutable $today): array
    {
        if ($due === null) {
            return ['no_due', 0];
        }

        $days = (int) $due->diffInDays($today, false);

        return [self::bucket($days), $days];
    }

    private static function bucket(int $daysPastDue): string
    {
        return match (true) {
            $daysPastDue <= 0  => 'current',
            $daysPastDue <= 30 => '1_30',
            $daysPastDue <= 60 => '31_60',
            $daysPastDue <= 90 => '61_90',
            default            => 'over_90',
        };
    }

    /**
     * @param  array<string, float>                                        $buckets
     * @param  array<int, array{id: int, name: string, amount: float}>     $parties
     * @return array<string, mixed>
     */
    private function shape(float $total, float $overdue, int $count, array $buckets, array $parties): array
    {
        usort($parties, fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return [
            'total'      => round($total, 2),
            'overdue'    => round($overdue, 2),
            'documents'  => $count,
            'parties'    => count($parties),
            'aging'      => array_map(
                fn (string $key): array => ['key' => $key, 'label' => self::BUCKETS[$key], 'amount' => round($buckets[$key], 2)],
                array_keys(self::BUCKETS),
            ),
            'top'        => array_map(
                fn (array $p): array => [...$p, 'amount' => round($p['amount'], 2)],
                array_slice($parties, 0, self::TOP_N),
            ),
        ];
    }
}
