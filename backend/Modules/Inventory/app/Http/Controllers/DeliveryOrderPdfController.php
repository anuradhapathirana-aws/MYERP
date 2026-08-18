<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Inventory\Models\DeliveryOrder;

class DeliveryOrderPdfController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view_delivery_orders');
    }

    public function download(DeliveryOrder $deliveryOrder): Response
    {
        $do = DeliveryOrder::with([
            'items.product',
            'items.unit',
            'items.attribute',
            'items.pieces.piece',
            'salesOrder',
            'customer',
            'driver',
            'vehicle',
            'store',
            'location.company',
        ])->findOrFail($deliveryOrder->id);

        $pdf = Pdf::loadView('inventory::pdf.delivery_order', ['do' => $do])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'defaultFont'          => 'DejaVu Sans',
                'isHtml5ParserEnabled' => true,
                'isPhpEnabled'         => false,
                'dpi'                  => 150,
                'defaultPaperSize'     => 'a4',
            ]);

        return $pdf->download("DO_{$do->do_no}.pdf");
    }
}
