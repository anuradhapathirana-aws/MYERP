<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Finance\Http\Requests\AccountTransactionsRequest;
use Modules\Finance\Http\Requests\LedgerFilterRequest;
use Modules\Finance\Http\Requests\TrialBalanceRequest;
use Modules\Finance\Models\LedgerAccount;
use Modules\Finance\Services\AccountTransactionsService;
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
    public function __construct(
        private readonly TrialBalanceService $trialBalance,
        private readonly AccountTransactionsService $accountTransactions,
    ) {
        $this->middleware('permission:view_finance_reports');
    }

    public function trialBalanceReport(TrialBalanceRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->trialBalance->build($request->filters())]);
    }

    /**
     * The drill-down behind one Trial Balance row. withTrashed: an archived
     * account with history still appears on the Trial Balance, so it must
     * still open here.
     */
    public function accountTransactions(AccountTransactionsRequest $request, int $ledgerAccount): JsonResponse
    {
        $account = $this->reportableAccount($request, $ledgerAccount);

        return response()->json([
            'data' => $this->accountTransactions->build($account, $request->filters(), $request->page()),
        ]);
    }

    /** The same account as a balanced T-account, whole period, A4 portrait. */
    public function accountTAccountPdf(AccountTransactionsRequest $request, int $ledgerAccount): Response
    {
        $account = $this->reportableAccount($request, $ledgerAccount);
        $data    = $this->accountTransactions->tAccount($account, $request->filters());

        $name = preg_replace('/[^A-Za-z0-9]+/', '_', (string) $account->ledger_account_name);

        return Pdf::loadView('finance::pdf.t_account', $data)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'defaultFont'          => 'DejaVu Sans',
                'isHtml5ParserEnabled' => true,
                'isPhpEnabled'         => false,
                'dpi'                  => 150,
            ])
            ->download('TAccount_' . $account->code . '_' . trim((string) $name, '_') . '.pdf');
    }

    /**
     * The account, if this user may report on it. An account owned by another
     * company (a bank or cash account) is refused outright; its lines would be
     * filtered out anyway, but its name and balance header would not.
     */
    private function reportableAccount(LedgerFilterRequest $request, int $id): LedgerAccount
    {
        $account = LedgerAccount::withTrashed()->findOrFail($id);
        $allowed = $request->allowedCompanyIds();

        abort_if(
            $allowed !== null && $account->company_id !== null && ! in_array((int) $account->company_id, $allowed, true),
            403,
            'This account belongs to a company you do not have access to.',
        );

        return $account;
    }
}
