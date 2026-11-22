<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use App\Models\User;
use App\Services\CompanyAccessService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Finance\DTOs\LedgerSelection;
use Modules\Finance\Http\Requests\LedgerDetailsRequest;
use Modules\Finance\Services\AccountTransactionsService;
use Modules\Finance\Services\LedgerDetailsService;

/**
 * Finance > General Ledger > Ledger Details.
 *
 * The lines of one account come from the Trial Balance drill-down endpoint
 * (FinanceReportController::accountTransactions); this controller only adds
 * what is new: the account selection, the per-account summary and the PDF.
 */
class LedgerDetailsController extends Controller
{
    public function __construct(
        private readonly LedgerDetailsService $service,
        private readonly CompanyAccessService $companyAccess,
    ) {
        $this->middleware('permission:view_finance_reports');
    }

    /** Accounts, control accounts, categories and types for the selection controls. */
    public function options(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $this->service->options(
                $this->companyAccess->allowedCompanyIds($user instanceof User ? $user : null),
            ),
        ]);
    }

    public function index(LedgerDetailsRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->report(
                LedgerSelection::fromRequest($request),
                $request->filters(),
                $request->page(),
            ),
        ]);
    }

    /** Every selected account with its lines: landscape list, or portrait T-accounts. */
    public function pdf(LedgerDetailsRequest $request): Response
    {
        $view = $request->view();
        $data = $this->service->printable(LedgerSelection::fromRequest($request), $request->filters(), $view);

        $isTAccount = $view === AccountTransactionsService::VIEW_T_ACCOUNT;

        return Pdf::loadView('finance::pdf.ledger_details', $data)
            ->setPaper('a4', $isTAccount ? 'portrait' : 'landscape')
            ->setOptions([
                'defaultFont'          => 'DejaVu Sans',
                'isHtml5ParserEnabled' => true,
                'isPhpEnabled'         => false,
                'dpi'                  => 150,
            ])
            ->download('Ledger_Details_' . now()->format('Ymd_His') . '.pdf');
    }
}
