<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Inventory\Enums\GrnStatus;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GrnItemPiece;

class GrnPieceLabelPdfController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view_grns');
    }

    public function download(GoodsReceivedNote $goodsReceivedNote): Response
    {
        abort_if($goodsReceivedNote->status !== GrnStatus::Confirmed, 422, 'Piece labels are only available for confirmed GRNs.');

        $pieces = GrnItemPiece::with(['product.baseUnit', 'parent:id,piece_code'])
            ->where('grn_id', $goodsReceivedNote->id)
            ->orderBy('grn_item_id')
            ->orderBy('piece_no')
            ->get()
            ->load('batch');

        abort_if($pieces->isEmpty(), 404, 'No pieces found for this GRN.');

        $frontendUrl = config('app.frontend_url');
        $writer      = new PngWriter();

        $labels = $pieces->map(fn (GrnItemPiece $piece) => [
            'piece_code'  => $piece->piece_code,
            'product'     => $piece->product,
            'batch_no'    => $piece->batch?->batch_no,
            'weight'      => $piece->weight,
            // Roll weights are sealed in the product's stocking UOM at GRN confirm,
            // so that is the unit the sticker must state — not the GRN's buying unit.
            'uom'         => $piece->product?->baseUnit?->symbol ?? $piece->product?->baseUnit?->name,
            'roll_no'     => $piece->roll_no,
            // Set when a sale cut this roll out of another: names the sticker it supersedes,
            // so the operator peels the stale one off instead of leaving two live QR codes.
            'replaces'    => $piece->parent?->piece_code,
            'qr_data_uri' => $writer->write(
                new QrCode(
                    data:   "{$frontendUrl}/inventory/pieces/{$piece->piece_code}",
                    size:   180,
                    margin: 4,
                )
            )->getDataUri(),
        ]);

        $pdf = Pdf::loadView('inventory::pdf.piece-labels', [
            'grn'    => $goodsReceivedNote,
            'labels' => $labels,
        ])->setPaper('a4', 'portrait')
          ->setOptions([
              'defaultFont'          => 'DejaVu Sans',
              'isHtml5ParserEnabled' => true,
              'isPhpEnabled'         => false,
              'dpi'                  => 150,
          ]);

        GrnItemPiece::where('grn_id', $goodsReceivedNote->id)
            ->whereNull('printed_at')
            ->update(['printed_at' => now()]);

        return $pdf->download("GRN_{$goodsReceivedNote->grn_no}_Piece_Labels.pdf");
    }
}
