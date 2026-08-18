<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Inventory\Models\PurchaseOrder;

class PurchaseOrderPdfController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view_purchase_orders');
    }

    public function download(PurchaseOrder $purchaseOrder): Response
    {
        $po = PurchaseOrder::with([
            'items.product',
            'items.unit',
            'items.attribute',
            'supplier',
            'store',
            'location.company',
            'createdBy',
        ])->findOrFail($purchaseOrder->id);

        $pdf = Pdf::loadView('inventory::pdf.purchase_order', ['po' => $po])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'defaultFont'     => 'DejaVu Sans',
                'isHtml5ParserEnabled' => true,
                'isPhpEnabled'    => false,
                'dpi'             => 150,
                'defaultPaperSize' => 'a4',
            ]);

        $filename = "PO_{$po->po_no}.pdf";

        return $pdf->download($filename);
    }
}
