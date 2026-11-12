<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Controllers;

use App\Models\Company;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Finance\Enums\ReconLineStatus;
use Modules\Finance\Models\BankReconciliation;
use Modules\Finance\Services\BankReconciliationService;

/**
 * The Bank Reconciliation Statement: balance per bank, balance per books, and
 * the entries in between (unpresented cheques, deposits in transit). A draft
 * prints too, marked as such, for review before posting.
 */
class BankReconciliationPdfController extends Controller
{
    public function __construct(private readonly BankReconciliationService $service)
    {
        $this->middleware('permission:view_bank_reconciliations');
    }

    public function download(BankReconciliation $bankReconciliation): Response
    {
        // find() also refuses a reconciliation of a company this user cannot see.
        $recon = $this->service->find($bankReconciliation);

        $realized      = $recon->lines->where('status', ReconLineStatus::Realized);
        $returned      = $recon->lines->where('status', ReconLineStatus::Returned);
        $returnedLater = $recon->lines->where('status', ReconLineStatus::ReturnedLater);

        // What the books held and the bank had not shown, as at the
        // statement date -- true for an old reconciliation too.
        $open  = $this->service->openItems($recon);
        $items = collect($open['items']);

        $pdf = Pdf::loadView('finance::pdf.bank_reconciliation', [
            'recon'         => $recon,
            'company'       => Company::query()->find($recon->company_id),
            'realized'      => $realized,
            'returned'      => $returned,
            'returnedLater' => $returnedLater,
            'unpresented'   => $items->where('direction', 'withdrawal')->values(),
            'inTransit'     => $items->where('direction', 'deposit')->values(),
            'openTruncated' => $open['truncated'],
            // The proof uses the database's sum of every open line, so it
            // stays right even when the lists above are cut.
            'openNet'       => $open['net_cents'] / 100,
            'openingGap'    => $this->service->openingGapCents($recon) / 100,
            'bookedAfter'   => $this->service->bookedAfterCents($recon) / 100,
            'cutoff'        => $this->service->accountCutoff($recon->bank_account_id) ?? $recon->date_from?->toDateString(),
            // Frozen at post; worked out now for a draft.
            'bookBalance' => $recon->book_balance !== null
                ? (float) $recon->book_balance
                : $this->service->bookBalanceCents($recon->bank_account_id, $recon->statement_date->toDateString()) / 100,
        ])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'defaultFont'          => 'DejaVu Sans',
                'isHtml5ParserEnabled' => true,
                'isPhpEnabled'         => false,
                'dpi'                  => 150,
            ]);

        return $pdf->download("BR_{$recon->recon_no}.pdf");
    }
}
