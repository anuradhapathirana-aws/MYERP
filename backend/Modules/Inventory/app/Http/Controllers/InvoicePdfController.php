<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Inventory\Models\Invoice;

class InvoicePdfController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view_invoices');
    }

    public function download(Invoice $invoice): Response
    {
        $inv = Invoice::with([
            'items.product',
            'items.unit',
            'items.attribute',
            'salesOrder',
            'deliveryOrder',
            'customer',
            // Supplier block on the tax invoice.
            'company',
        ])->findOrFail($invoice->id);

        $pdf = Pdf::loadView('inventory::pdf.invoice', ['invoice' => $inv])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'defaultFont'          => 'DejaVu Sans',
                'isHtml5ParserEnabled' => true,
                'isPhpEnabled'         => false,
                'dpi'                  => 150,
                'defaultPaperSize'     => 'a4',
            ]);

        return $pdf->download("INV_{$inv->invoice_no}.pdf");
    }
}
