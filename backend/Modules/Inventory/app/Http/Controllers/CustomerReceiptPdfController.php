<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Inventory\Enums\CustomerReceiptStatus;
use Modules\Inventory\Models\Company;
use Modules\Inventory\Models\CustomerReceipt;
use Modules\Inventory\Services\CustomerReceiptService;

class CustomerReceiptPdfController extends Controller
{
    public function __construct(private readonly CustomerReceiptService $service)
    {
        $this->middleware('permission:view_customer_receipts');
    }

    public function download(CustomerReceipt $customerReceipt): Response
    {
        // A draft is still editable — its figures aren't final, so it has no printable receipt.
        if ($customerReceipt->status !== CustomerReceiptStatus::Confirmed) {
            abort(422, 'Only confirmed receipts can be printed.');
        }

        // find() attaches the customer snapshot and live invoice numbers to the allocations
        $receipt = $this->service->find($customerReceipt->id);

        // Single-tenant deployment — the letterhead is the deployment's own company record
        $company = Company::query()->orderBy('id')->first();

        $pdf = Pdf::loadView('inventory::pdf.customer_receipt', [
            'receipt' => $receipt,
            'company' => $company,
        ])
            ->setPaper('a5', 'portrait')
            ->setOptions([
                'defaultFont'          => 'DejaVu Sans',
                'isHtml5ParserEnabled' => true,
                'isPhpEnabled'         => false,
                'dpi'                  => 150,
            ]);

        return $pdf->download("RCP_{$receipt->receipt_no}.pdf");
    }
}
