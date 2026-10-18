<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Inventory\Models\ProformaInvoice;

class ProformaInvoicePdfController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view_proforma_invoices');
    }

    /** Printed on the invoice layout, titled "Proforma Invoice". */
    public function download(ProformaInvoice $proformaInvoice): Response
    {
        $proforma = ProformaInvoice::with([
            'items.product',
            'items.unit',
            'items.attribute',
            'items.variant',
            'salesOrder',
            'customer',
            'company',
        ])->findOrFail($proformaInvoice->id);

        $pdf = Pdf::loadView('inventory::pdf.invoice', ['invoice' => $proforma, 'isProforma' => true])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'defaultFont'          => 'DejaVu Sans',
                'isHtml5ParserEnabled' => true,
                'isPhpEnabled'         => false,
                'dpi'                  => 150,
                'defaultPaperSize'     => 'a4',
            ]);

        return $pdf->download("PI_{$proforma->proforma_no}.pdf");
    }
}
