<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Finance\Services\TrialBalanceService;

/**
 * Financial statements, all derived from the two journal tables.
 *
 * Trial Balance first because it is the health check: if its two totals ever
 * disagree, something wrote to fin_journal_lines without going through
 * JournalPostingService, and no other report can be trusted until that is
 * found. Balance Sheet, Profit & Loss and the aged reports are the same query
 * with different grouping, and land in 3C.
 */
class FinanceReportController extends Controller
{
    public function __construct(private readonly TrialBalanceService $trialBalance)
    {
        $this->middleware('permission:view_finance_reports');
    }

    public function trialBalanceReport(): JsonResponse
    {
        $filters = request()->only(['date_from', 'date_to', 'company_id', 'location_id']);

        return response()->json(['data' => $this->trialBalance->build($filters)]);
    }
}
