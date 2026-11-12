<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Inventory\Enums\DashboardPeriod;
use Modules\Inventory\Services\Dashboard\AgingDashboardService;
use Modules\Inventory\Services\Dashboard\OrdersDashboardService;
use Modules\Inventory\Services\Dashboard\SalesDashboardService;
use Modules\Inventory\Services\Dashboard\StockDashboardService;

/**
 * Inventory's widgets on the home dashboard. Each section is its own endpoint
 * with its own permission, so the page shows a user exactly the sections their
 * role already lets them see elsewhere — the dashboard never reveals more.
 */
class DashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view_dashboard');
        $this->middleware('permission:view_invoices')->only(['sales', 'receivables']);
        $this->middleware('permission:view_supplier_payments')->only(['payables']);
        $this->middleware('permission:view_reports')->only(['stock']);
        $this->middleware('permission:view_sales_orders')->only(['orders']);
    }

    public function sales(Request $request, SalesDashboardService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->build(
                $this->period($request),
                $request->user()->can('view_customer_receipts'),
            ),
        ]);
    }

    public function orders(Request $request, OrdersDashboardService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->build(
                $this->period($request),
                $request->user()->can('view_delivery_orders'),
            ),
        ]);
    }

    public function receivables(AgingDashboardService $service): JsonResponse
    {
        return response()->json(['data' => $service->receivables()]);
    }

    public function payables(AgingDashboardService $service): JsonResponse
    {
        return response()->json(['data' => $service->payables()]);
    }

    public function stock(Request $request, StockDashboardService $service): JsonResponse
    {
        return response()->json(['data' => $service->build($this->period($request))]);
    }

    private function period(Request $request): DashboardPeriod
    {
        $request->validate([
            'period' => ['nullable', Rule::enum(DashboardPeriod::class)],
        ]);

        return DashboardPeriod::tryFrom((string) $request->query('period')) ?? DashboardPeriod::Month;
    }
}
