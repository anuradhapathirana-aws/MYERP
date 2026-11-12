<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\DashboardPeriod;
use Modules\Inventory\Enums\GrnStatus;
use Modules\Inventory\Enums\PurchaseOrderStatus;
use Modules\Inventory\Enums\PurchaseRequestStatus;

/**
 * Stock & Purchasing figures for the dashboard.
 *
 *   Stock value — on-hand quantity (balance cache) × weighted average purchase
 *                 cost, the same formula as the Stock Valuation report.
 *   Low / out   — products with a reorder level, totalled over every store and
 *                 location, at or below that level (out = nothing left).
 */
class StockDashboardService
{
    private const ALERT_ROWS      = 8;
    private const RECENT_GRNS     = 5;
    private const PURCHASE_MONTHS = 6;

    /** @return array<string, mixed> */
    public function build(DashboardPeriod $period): array
    {
        [$from, $to] = $period->range();

        return [
            'stock_value'    => $this->stockValue(),
            'alerts'         => $this->reorderAlerts(),
            'purchasing'     => $this->purchasing($from, $to),
            'recent_grns'    => $this->recentGrns(),
            'purchase_trend' => $this->purchaseTrend(),
        ];
    }

    private function stockValue(): float
    {
        $wac = DB::raw(
            '(SELECT product_id, SUM(qty_in * unit_price) / NULLIF(SUM(qty_in), 0) AS avg_unit_cost
              FROM inv_stock_transactions
              WHERE qty_in > 0
              GROUP BY product_id) AS wac'
        );

        return round((float) DB::table('inv_product_location_stores as pls')
            ->leftJoin($wac, 'wac.product_id', '=', 'pls.product_id')
            ->where('pls.current_stock', '>', 0)
            ->sum(DB::raw('pls.current_stock * COALESCE(wac.avg_unit_cost, 0)')), 2);
    }

    /** @return array{tracked_count: int, low_count: int, out_count: int, items: list<array<string, mixed>>} */
    private function reorderAlerts(): array
    {
        $onHand = DB::table('inv_product_location_stores')
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(current_stock) as qty');

        $rows = DB::table('inv_products as p')
            ->leftJoinSub($onHand, 'st', 'st.product_id', '=', 'p.id')
            ->where('p.reorder_level', '>', 0)
            ->whereRaw('COALESCE(st.qty, 0) <= p.reorder_level')
            ->selectRaw('p.id, p.product_code, p.name, p.reorder_level, COALESCE(st.qty, 0) as qty')
            ->get();

        $outCount = $rows->filter(fn ($r): bool => (float) $r->qty <= 0)->count();

        // Out of stock first, then the ones furthest below their level.
        $items = $rows
            ->sortBy(fn ($r): float => (float) $r->qty / max((float) $r->reorder_level, 0.0001))
            ->take(self::ALERT_ROWS)
            ->map(fn ($r): array => [
                'id'            => (int) $r->id,
                'code'          => $r->product_code,
                'name'          => (string) $r->name,
                'quantity'      => round((float) $r->qty, 2),
                'reorder_level' => round((float) $r->reorder_level, 2),
                'status'        => (float) $r->qty <= 0 ? 'out' : 'low',
            ])
            ->values()
            ->all();

        return [
            // Products with a reorder level at all — 0 means no alert can ever show.
            'tracked_count' => DB::table('inv_products')->where('reorder_level', '>', 0)->count(),
            'low_count' => $rows->count() - $outCount,
            'out_count' => $outCount,
            'items'     => $items,
        ];
    }

    /** @return array<string, int|float> */
    private function purchasing(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $openStatuses = [
            PurchaseOrderStatus::Sent->value,
            PurchaseOrderStatus::Confirmed->value,
            PurchaseOrderStatus::PartiallyReceived->value,
        ];

        $openPos = DB::table('inv_purchase_orders')
            ->whereIn('status', $openStatuses)
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(grand_total), 0) as total')
            ->first();

        $overduePos = DB::table('inv_purchase_orders')
            ->whereIn('status', $openStatuses)
            ->whereNull('deleted_at')
            ->whereNotNull('expected_delivery_date')
            ->where('expected_delivery_date', '<', CarbonImmutable::today()->toDateString())
            ->count();

        $grns = DashboardQuery::inDays(
            DB::table('inv_goods_received_notes')
                ->where('status', GrnStatus::Confirmed->value)
                ->whereNull('deleted_at'),
            'grn_date', $from, $to,
        )->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as total')->first();

        $prCount = fn (PurchaseRequestStatus $status): int => DB::table('inv_purchase_requests')
            ->where('status', $status->value)
            ->whereNull('deleted_at')
            ->count();

        return [
            'pending_prs'   => $prCount(PurchaseRequestStatus::Submitted),
            'approved_prs'  => $prCount(PurchaseRequestStatus::Approved),
            'open_pos'      => (int) $openPos->cnt,
            'open_po_value' => round((float) $openPos->total, 2),
            'overdue_pos'   => $overduePos,
            'grn_count'     => (int) $grns->cnt,
            'grn_value'     => round((float) $grns->total, 2),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentGrns(): array
    {
        return DB::table('inv_goods_received_notes as g')
            ->leftJoin('inv_supplier_masters as s', 's.id', '=', 'g.supplier_id')
            ->where('g.status', GrnStatus::Confirmed->value)
            ->whereNull('g.deleted_at')
            ->orderByDesc('g.grn_date')
            ->orderByDesc('g.id')
            ->limit(self::RECENT_GRNS)
            ->get(['g.id', 'g.grn_no', 'g.grn_date', 'g.total_amount', 's.supplier_name'])
            ->map(fn ($g): array => [
                'id'       => (int) $g->id,
                'grn_no'   => $g->grn_no,
                'date'     => substr((string) $g->grn_date, 0, 10),
                'supplier' => $g->supplier_name,
                'amount'   => round((float) $g->total_amount, 2),
            ])
            ->all();
    }

    /** @return list<array{month: string, label: string, amount: float}> */
    private function purchaseTrend(): array
    {
        $end   = CarbonImmutable::today()->endOfMonth()->startOfDay();
        $start = $end->subMonthsNoOverflow(self::PURCHASE_MONTHS - 1)->startOfMonth();

        $totals = DashboardQuery::inDays(
            DB::table('inv_goods_received_notes as g')
                ->where('g.status', GrnStatus::Confirmed->value)
                ->whereNull('g.deleted_at'),
            'g.grn_date', $start, $end,
        )
            ->groupBy(DB::raw(DashboardQuery::monthKey('g.grn_date')))
            ->selectRaw(DashboardQuery::monthKey('g.grn_date') . ' as ym, SUM(g.total_amount) as total')
            ->pluck('total', 'ym');

        $rows = [];

        for ($m = $start; $m->lte($end); $m = $m->addMonthNoOverflow()) {
            $key    = $m->format('Y-m');
            $rows[] = ['month' => $key, 'label' => $m->format('M y'), 'amount' => round((float) ($totals[$key] ?? 0), 2)];
        }

        return $rows;
    }
}
