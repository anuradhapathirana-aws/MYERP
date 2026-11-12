<?php

declare(strict_types=1);

namespace Modules\Inventory\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\DashboardPeriod;
use Modules\Inventory\Enums\DeliveryOrderStatus;
use Modules\Inventory\Enums\SalesOrderStatus;

/**
 * Sales Orders and Delivery Orders for the dashboard, by status.
 *
 *   Sales Orders    — dated (order_date) in the selected period; value = SO grand total.
 *   Delivery Orders — dated (delivery_date) in the selected period; a DO has no value
 *                     of its own, so only counts.
 *
 * Delivery Orders are only included when the caller may see them.
 */
class OrdersDashboardService
{
    /** @return array<string, mixed> */
    public function build(DashboardPeriod $period, bool $withDeliveries): array
    {
        [$from, $to] = $period->range();

        $so = DashboardQuery::inDays(
            DB::table('inv_sales_orders')->whereNull('deleted_at'),
            'order_date', $from, $to,
        )
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as cnt, COALESCE(SUM(grand_total), 0) as total')
            ->get()
            ->keyBy('status');

        $salesOrders = [];
        foreach (SalesOrderStatus::cases() as $status) {
            $row = $so[$status->value] ?? null;
            $salesOrders[$status->value] = [
                'count' => (int) ($row->cnt ?? 0),
                'value' => round((float) ($row->total ?? 0), 2),
            ];
        }

        $deliveryOrders = null;

        if ($withDeliveries) {
            $do = DashboardQuery::inDays(
                DB::table('inv_delivery_orders')->whereNull('deleted_at'),
                'delivery_date', $from, $to,
            )
                ->groupBy('status')
                ->selectRaw('status, COUNT(*) as cnt')
                ->pluck('cnt', 'status');

            $deliveryOrders = [];
            foreach (DeliveryOrderStatus::cases() as $status) {
                $deliveryOrders[$status->value] = (int) ($do[$status->value] ?? 0);
            }
        }

        return [
            'sales_orders'    => $salesOrders,
            'delivery_orders' => $deliveryOrders,
        ];
    }
}
