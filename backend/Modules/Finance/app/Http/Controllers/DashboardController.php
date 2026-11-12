<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Finance\Services\DashboardService;

/**
 * Finance's widget on the home dashboard. Cash and bank balances are the
 * company position, so it needs the same right as the Trial Balance.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $service)
    {
        $this->middleware('permission:view_dashboard');
        $this->middleware('permission:view_finance_reports');
    }

    public function cash(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->cash($request->user())]);
    }
}
