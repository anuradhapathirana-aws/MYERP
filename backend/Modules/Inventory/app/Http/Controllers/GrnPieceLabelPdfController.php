<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Inventory\Enums\GrnStatus;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Services\PieceLabelPdfRenderer;

class GrnPieceLabelPdfController extends Controller
{
    public function __construct(private readonly PieceLabelPdfRenderer $renderer)
    {
        $this->middleware('permission:view_grns');
    }

    public function download(GoodsReceivedNote $goodsReceivedNote): Response
    {
        abort_if($goodsReceivedNote->status !== GrnStatus::Confirmed, 422, 'Piece labels are only available for confirmed GRNs.');

        $pieces = GrnItemPiece::with(['product.baseUnit', 'parent:id,piece_code', 'batch:id,batch_no'])
            ->where('grn_id', $goodsReceivedNote->id)
            ->orderBy('grn_item_id')
            ->orderBy('piece_no')
            ->get();

        abort_if($pieces->isEmpty(), 404, 'No pieces found for this GRN.');

        $labels = $pieces->map(fn (GrnItemPiece $piece) => [
            'piece_code'   => $piece->piece_code,
            'product_name' => $piece->product?->name,
            'color'        => null,
            'batch_no'     => $piece->batch?->batch_no,
            'weight'       => $piece->weight,
            // Roll weights are sealed in the product's stocking UOM at GRN confirm,
            // so that is the unit the sticker must state — not the GRN's buying unit.
            'uom'          => $piece->product?->baseUnit?->symbol ?? $piece->product?->baseUnit?->name,
            'roll_no'      => $piece->roll_no,
            // Set when a sale cut this roll out of another: names the sticker it supersedes,
            // so the operator peels the stale one off instead of leaving two live QR codes.
            'replaces'     => $piece->parent?->piece_code,
        ]);

        $content = $this->renderer->a4Sheet((string) $goodsReceivedNote->grn_no, $labels);

        GrnItemPiece::where('grn_id', $goodsReceivedNote->id)
            ->whereNull('printed_at')
            ->update(['printed_at' => now()]);

        return response($content, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"GRN_{$goodsReceivedNote->grn_no}_Piece_Labels.pdf\"",
        ]);
    }
}
