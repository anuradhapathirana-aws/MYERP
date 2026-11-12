<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\CustomerReceiptStatus;
use Modules\Inventory\Enums\CustomerReturnStatus;
use Modules\Inventory\Enums\DashboardPeriod;
use Modules\Inventory\Enums\InvoiceStatus;

/**
 * Sales & Collections figures for the dashboard.
 *
 *   Net sales   = invoices billed in the period (Issued, Paid or Returned — never
 *                 Draft or Cancelled) minus the confirmed customer returns against
 *                 THOSE invoices dated up to the period end. The same rule as the
 *                 Sales Summary report's Net Sale, so the two always agree.
 *   Collections = money received on confirmed Customer Receipts in the period, by
 *                 payment method (settlement lines, the same source as the
 *                 Sales Summary report).
 *
 * Collections are only included when the caller may see customer receipts —
 * staff do not by default.
 */
class SalesDashboardService
{
    private const TREND_MONTHS = 12;
    private const TOP_N        = 5;

    /** @return array<string, mixed> */
    public function build(DashboardPeriod $period, bool $withCollections): array
    {
        [$from, $to]         = $period->range();
        [$prevFrom, $prevTo] = $period->previousRange();

        $sales     = $this->netSales($from, $to);
        $prevSales = $this->netSales($prevFrom, $prevTo);

        $kpis = [
            'net_sales'          => $sales['net'],
            'net_sales_previous' => $prevSales['net'],
            'invoiced'           => $sales['invoiced'],
            'returns'            => $sales['returns'],
            'invoice_count'      => $sales['count'],
            'average_invoice'    => $sales['count'] > 0 ? round($sales['invoiced'] / $sales['count'], 2) : 0.0,
        ];

        if ($withCollections) {
            $kpis['collections']          = $this->collections($from, $to);
            $kpis['collections_previous'] = $this->collections($prevFrom, $prevTo);
        }

        return [
            'period'           => ['key' => $period->value, 'label' => $period->label(), 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'kpis'             => $kpis,
            'trend'            => $this->trend($withCollections),
            'top_products'     => $this->topProducts($from, $to),
            'top_customers'    => $this->topCustomers($from, $to),
            'collection_modes' => $withCollections ? $this->collectionModes($from, $to) : null,
        ];
    }

    private function soldInvoices(): Builder
    {
        return DB::table('inv_invoices as i')
            ->whereIn('i.status', [InvoiceStatus::Issued->value, InvoiceStatus::Paid->value, InvoiceStatus::Returned->value])
            ->whereNull('i.deleted_at');
    }

    private function settlements(): Builder
    {
        return DB::table('inv_customer_receipt_settlements as s')
            ->join('inv_customer_receipts as r', 'r.id', '=', 's.receipt_id')
            ->where('r.status', CustomerReceiptStatus::Confirmed->value)
            ->whereNull('r.deleted_at');
    }

    /** @return array{invoiced: float, returns: float, net: float, count: int} */
    private function netSales(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = DashboardQuery::inDays($this->soldInvoices(), 'i.invoice_date', $from, $to)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(i.grand_total), 0) as total')
            ->first();

        $returns = (float) DashboardQuery::inDays($this->soldInvoices(), 'i.invoice_date', $from, $to)
            ->join('inv_customer_returns as cr', 'cr.invoice_id', '=', 'i.id')
            ->where('cr.status', CustomerReturnStatus::Confirmed->value)
            ->whereNull('cr.deleted_at')
            ->where('cr.return_date', '<', $to->addDay()->toDateString())
            ->sum('cr.total_amount');

        $invoiced = round((float) $row->total, 2);

        return [
            'invoiced' => $invoiced,
            'returns'  => round($returns, 2),
            'net'      => round($invoiced - $returns, 2),
            'count'    => (int) $row->cnt,
        ];
    }

    private function collections(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return round((float) DashboardQuery::inDays($this->settlements(), 'r.receipt_date', $from, $to)->sum('s.amount'), 2);
    }

    /**
     * Last 12 calendar months, oldest first, every month present even when empty
     * so the chart's x-axis never skips.
     *
     * @return list<array{month: string, label: string, sales: float, collections: float|null}>
     */
    private function trend(bool $withCollections): array
    {
        $end   = CarbonImmutable::today()->endOfMonth()->startOfDay();
        $start = $end->subMonthsNoOverflow(self::TREND_MONTHS - 1)->startOfMonth();

        $invoiced = DashboardQuery::inDays($this->soldInvoices(), 'i.invoice_date', $start, $end)
            ->groupBy(DB::raw(DashboardQuery::monthKey('i.invoice_date')))
            ->selectRaw(DashboardQuery::monthKey('i.invoice_date') . ' as ym, SUM(i.grand_total) as total')
            ->pluck('total', 'ym');

        // Each month = what the Sales Summary report gives for that month: returns against
        // the month's invoices, dated by that month's end (a later return is not deducted).
        $returned = [];
        DashboardQuery::inDays($this->soldInvoices(), 'i.invoice_date', $start, $end)
            ->join('inv_customer_returns as cr', 'cr.invoice_id', '=', 'i.id')
            ->where('cr.status', CustomerReturnStatus::Confirmed->value)
            ->whereNull('cr.deleted_at')
            ->get(['i.invoice_date', 'cr.return_date', 'cr.total_amount'])
            ->each(function ($r) use (&$returned): void {
                $month = substr((string) $r->invoice_date, 0, 7);
                if (substr((string) $r->return_date, 0, 7) <= $month) {
                    $returned[$month] = ($returned[$month] ?? 0.0) + (float) $r->total_amount;
                }
            });

        $collected = $withCollections
            ? DashboardQuery::inDays($this->settlements(), 'r.receipt_date', $start, $end)
                ->groupBy(DB::raw(DashboardQuery::monthKey('r.receipt_date')))
                ->selectRaw(DashboardQuery::monthKey('r.receipt_date') . ' as ym, SUM(s.amount) as total')
                ->pluck('total', 'ym')
            : collect();

        $rows = [];

        for ($m = $start; $m->lte($end); $m = $m->addMonthNoOverflow()) {
            $key    = $m->format('Y-m');
            $rows[] = [
                'month'       => $key,
                'label'       => $m->format('M y'),
                'sales'       => round((float) ($invoiced[$key] ?? 0) - (float) ($returned[$key] ?? 0), 2),
                'collections' => $withCollections ? round((float) ($collected[$key] ?? 0), 2) : null,
            ];
        }

        return $rows;
    }

    /** @return list<array{id: int, name: string, code: string|null, amount: float, quantity: float}> */
    private function topProducts(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DashboardQuery::inDays($this->soldInvoices(), 'i.invoice_date', $from, $to)
            ->join('inv_invoice_items as ii', 'ii.invoice_id', '=', 'i.id')
            ->join('inv_products as p', 'p.id', '=', 'ii.product_id')
            ->groupBy('p.id', 'p.name', 'p.product_code')
            ->selectRaw('p.id, p.name, p.product_code, SUM(ii.line_total) as amount, SUM(ii.quantity) as quantity')
            ->orderByDesc('amount')
            ->limit(self::TOP_N)
            ->get()
            ->map(fn ($r): array => [
                'id'       => (int) $r->id,
                'name'     => (string) $r->name,
                'code'     => $r->product_code,
                'amount'   => round((float) $r->amount, 2),
                'quantity' => round((float) $r->quantity, 2),
            ])
            ->all();
    }

    /** @return list<array{id: int, name: string, amount: float, invoices: int}> */
    private function topCustomers(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DashboardQuery::inDays($this->soldInvoices(), 'i.invoice_date', $from, $to)
            ->join('inv_customer_masters as cu', 'cu.id', '=', 'i.customer_id')
            ->groupBy('cu.id', 'cu.customer_name')
            ->selectRaw('cu.id, cu.customer_name, SUM(i.grand_total) as amount, COUNT(*) as invoices')
            ->orderByDesc('amount')
            ->limit(self::TOP_N)
            ->get()
            ->map(fn ($r): array => [
                'id'       => (int) $r->id,
                'name'     => (string) $r->customer_name,
                'amount'   => round((float) $r->amount, 2),
                'invoices' => (int) $r->invoices,
            ])
            ->all();
    }

    /** @return list<array{mode: string, amount: float}> */
    private function collectionModes(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DashboardQuery::inDays($this->settlements(), 'r.receipt_date', $from, $to)
            ->groupBy('s.payment_mode_name')
            ->selectRaw('s.payment_mode_name as mode, SUM(s.amount) as amount')
            ->orderByDesc('amount')
            ->get()
            ->filter(fn ($r): bool => (float) $r->amount > 0)
            ->map(fn ($r): array => ['mode' => (string) $r->mode, 'amount' => round((float) $r->amount, 2)])
            ->values()
            ->all();
    }
}
