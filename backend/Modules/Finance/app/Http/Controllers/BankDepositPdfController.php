<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use App\Models\Company;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Finance\Enums\BankDepositStatus;
use Modules\Finance\Models\BankDeposit;
use Modules\Finance\Services\BankDepositService;

/**
 * The paying-in slip: what is carried to the bank counter.
 *
 * A draft prints too, marked as such -- the slip is written BEFORE the visit,
 * and the deposit is confirmed once the bank has stamped it. A voided deposit
 * never went in, so it has no slip.
 */
class BankDepositPdfController extends Controller
{
    public function __construct(private readonly BankDepositService $service)
    {
        $this->middleware('permission:view_bank_deposits');
    }

    public function download(BankDeposit $bankDeposit): Response
    {
        if ($bankDeposit->status === BankDepositStatus::Voided) {
            abort(422, 'A voided deposit has no slip to print.');
        }

        // find() also refuses a deposit of a company this user cannot see.
        $deposit = $this->service->find($bankDeposit);

        $pdf = Pdf::loadView('finance::pdf.bank_deposit', [
            'deposit' => $deposit,
            'company' => Company::query()->find($deposit->company_id),
        ])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'defaultFont'          => 'DejaVu Sans',
                'isHtml5ParserEnabled' => true,
                'isPhpEnabled'         => false,
                'dpi'                  => 150,
            ]);

        return $pdf->download("BD_{$deposit->deposit_no}.pdf");
    }
}
