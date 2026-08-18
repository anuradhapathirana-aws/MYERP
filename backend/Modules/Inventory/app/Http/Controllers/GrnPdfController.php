<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Inventory\Models\GoodsReceivedNote;

class GrnPdfController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view_grns');
    }

    public function download(GoodsReceivedNote $goodsReceivedNote): Response
    {
        $grn = GoodsReceivedNote::with([
            'items.product',
            'items.unit',
            'items.attribute',
            'purchaseOrder',
            'supplier',
            'store',
            'location.company',
            'receivedBy',
        ])->findOrFail($goodsReceivedNote->id);

        $pdf = Pdf::loadView('inventory::pdf.grn', ['grn' => $grn])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'defaultFont'     => 'DejaVu Sans',
                'isHtml5ParserEnabled' => true,
                'isPhpEnabled'    => false,
                'dpi'             => 150,
                'defaultPaperSize' => 'a4',
            ]);

        $filename = "GRN_{$grn->grn_no}.pdf";

        return $pdf->download($filename);
    }
}
