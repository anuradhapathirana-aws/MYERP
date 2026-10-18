<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Inventory\Enums\GrnStatus;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GoodsReceivedNoteItem;

/**
 * Stickers for finished goods received on a GRN: one QR label per unit of each
 * variant line (Red / Floral / M), the count editable before printing. The QR
 * carries the variant's barcode (else its SKU) — what the line pickers scan.
 */
class GrnVariantLabelController extends Controller
{
    /**
     * Labels per PDF file — protects DomPDF. A GRN of 7,900 pieces prints as sixteen
     * files: the form asks for each batch with ?offset=0, 500, 1000 …
     */
    public const BATCH_SIZE = 500;

    /** Sanity cap on one line's count (a typo of 250000 instead of 2500). */
    private const MAX_PER_LINE = 100000;

    public function __construct()
    {
        $this->middleware('permission:view_grns');
    }

    /** GET /goods-received-notes/{grn}/variant-labels — the variant lines with their default label count. */
    public function index(GoodsReceivedNote $goodsReceivedNote): JsonResponse
    {
        return response()->json(['data' => $this->lines($goodsReceivedNote)->map(fn (GoodsReceivedNoteItem $item) => [
            'grn_item_id'   => $item->id,
            'product_name'  => $item->product?->name,
            'variant_label' => $item->variant?->variant_label,
            'sku'           => $item->variant?->sku,
            'quantity'      => (float) $item->quantity_received,
            'default_count' => (int) ceil((float) $item->quantity_received),
        ])->values()]);
    }

    /** GET /goods-received-notes/{grn}/variant-labels/pdf?counts[grn_item_id]=n */
    public function pdf(Request $request, GoodsReceivedNote $goodsReceivedNote): Response
    {
        abort_if($goodsReceivedNote->status !== GrnStatus::Confirmed, 422, 'Variant labels are only available for confirmed GRNs.');

        $request->validate([
            'counts'   => ['nullable', 'array'],
            'counts.*' => ['integer', 'min:0', 'max:' . self::MAX_PER_LINE],
            'offset'   => ['nullable', 'integer', 'min:0'],
        ], [
            'counts.*.max' => 'A line can print at most ' . number_format(self::MAX_PER_LINE) . ' labels.',
        ]);

        $lines = $this->lines($goodsReceivedNote);
        abort_if($lines->isEmpty(), 404, 'This GRN has no variant lines.');

        $counts = (array) $request->input('counts', []);
        $offset = (int) $request->input('offset', 0);
        $writer = new PngWriter();
        $labels = [];
        $index  = 0; // position of the next label across the whole print

        foreach ($lines as $item) {
            // Not sent = one label per unit received
            $count = isset($counts[$item->id])
                ? (int) $counts[$item->id]
                : (int) ceil((float) $item->quantity_received);

            // Only the slice of this line that falls inside the requested batch
            $from = max(0, $offset - $index);
            $to   = min($count, $offset + self::BATCH_SIZE - $index);
            $index += $count;

            if ($count <= 0 || $from >= $to) {
                continue;
            }

            $code = $item->variant->barcode ?: $item->variant->sku;
            // One QR image per line, reused for each of its stickers.
            $qr = $writer->write(new QrCode(data: $code, size: 180, margin: 4))->getDataUri();

            for ($i = $from; $i < $to; $i++) {
                $labels[] = [
                    'qr_data_uri'   => $qr,
                    'product_name'  => $item->product?->name,
                    'variant_label' => $item->variant->variant_label,
                ];
            }
        }

        abort_if($labels === [], 422, 'Enter at least one label to print.');

        $pdf = Pdf::loadView('inventory::pdf.variant-labels', [
            'grn'    => $goodsReceivedNote,
            'labels' => collect($labels),
        ])->setPaper('a4', 'portrait')
          ->setOptions([
              'defaultFont'          => 'DejaVu Sans',
              'isHtml5ParserEnabled' => true,
              'isPhpEnabled'         => false,
              'dpi'                  => 150,
          ]);

        return $pdf->download("GRN_{$goodsReceivedNote->grn_no}_Variant_Labels.pdf");
    }

    /** @return \Illuminate\Support\Collection<int, GoodsReceivedNoteItem> */
    private function lines(GoodsReceivedNote $grn)
    {
        return GoodsReceivedNoteItem::with(['product:id,name', 'variant:id,sku,barcode,variant_label'])
            ->where('grn_id', $grn->id)
            ->whereNotNull('variant_id')
            ->orderBy('id')
            ->get();
    }
}
