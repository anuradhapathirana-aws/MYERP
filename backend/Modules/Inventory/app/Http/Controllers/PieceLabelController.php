<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Modules\Inventory\Enums\GrnStatus;
use Modules\Inventory\Models\GoodsReceivedNote;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Services\PieceLabelPdfRenderer;
use Modules\Inventory\Services\PieceQrCodeService;

/**
 * Cross-GRN piece label lookup — filter sealed pieces by product / shipping
 * code / color and print their QR labels (see GrnPieceLabelPdfController for
 * the per-GRN variant).
 */
class PieceLabelController extends Controller
{
    /** Labels shown on screen — each carries its QR as an <img>. */
    private const MAX_LABELS = 500;

    /** Labels in one PDF. Covers the largest GRN; drawing costs a few ms per label. */
    public const MAX_PDF_LABELS = 2000;

    public function __construct(
        private readonly PieceQrCodeService $qr,
        private readonly PieceLabelPdfRenderer $renderer,
    ) {
        $this->middleware('permission:view_grns');
    }

    /** GET /piece-labels?product_id=&shipping_code=&attribute_id= — labels with QR data URIs */
    public function index(Request $request): JsonResponse
    {
        $filters = $this->validateFilters($request);

        $total  = $this->pieceQuery($filters)->count();
        $pieces = $this->pieceQuery($filters)->limit(self::MAX_LABELS)->get();

        return response()->json([
            'data' => [
                'labels'    => $this->buildLabels($pieces, withQr: true),
                'truncated' => $total > self::MAX_LABELS,
                // The PDF prints up to MAX_PDF_LABELS, so the page can say how many
                // labels the print will hold beyond the ones previewed.
                'total'     => $total,
                'pdf_limit' => self::MAX_PDF_LABELS,
            ],
        ]);
    }

    /**
     * GET /piece-labels/pdf — printable PDF for the same filters.
     *
     * The PDF page is one physical row of the 2-up sticker roll loaded in the
     * TSC label printer: [38mm sticker][3mm gap][38mm sticker] x 25mm high.
     * The driver's stock must be configured to the same 79x25mm size.
     */
    public function pdf(Request $request): Response
    {
        $filters = $this->validateFilters($request);

        $pieces = $this->pieceQuery($filters)->limit(self::MAX_PDF_LABELS)->get();
        abort_if($pieces->isEmpty(), 404, 'No pieces found for the selected filters.');

        $content = $this->renderer->stickerRoll($this->buildLabels($pieces, withQr: false));

        GrnItemPiece::whereIn('id', $pieces->pluck('id'))
            ->whereNull('printed_at')
            ->update(['printed_at' => now()]);

        return response($content, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Piece_Labels.pdf"',
        ]);
    }

    /** GET /piece-labels/grn-nos — GRN numbers of confirmed GRNs for the filter dropdown */
    public function grnNos(): JsonResponse
    {
        $numbers = GoodsReceivedNote::query()
            ->where('status', GrnStatus::Confirmed)
            ->orderByDesc('id')
            ->pluck('grn_no')
            ->filter()
            ->unique()
            ->values();

        return response()->json(['data' => $numbers]);
    }

    /** GET /piece-labels/shipping-codes — distinct codes of confirmed GRNs for the filter dropdown */
    public function shippingCodes(): JsonResponse
    {
        $codes = GoodsReceivedNote::query()
            ->where('status', GrnStatus::Confirmed)
            ->whereNotNull('shipping_code')
            ->where('shipping_code', '!=', '')
            ->orderByDesc('id')
            ->pluck('shipping_code')
            ->unique()
            ->values();

        return response()->json(['data' => $codes]);
    }

    /** @return array{product_id?: int, shipping_code?: string, attribute_id?: int, grn_no?: string, print_status?: string} */
    private function validateFilters(Request $request): array
    {
        // print_status counts as a filter in its own right: "every sticker I still owe"
        // is a complete question, and the most common reason to open this page.
        return $request->validate([
            'product_id'    => ['nullable', 'integer', 'exists:inv_products,id', 'required_without_all:shipping_code,attribute_id,grn_no,print_status'],
            'shipping_code' => ['nullable', 'string', 'max:100', 'required_without_all:product_id,attribute_id,grn_no,print_status'],
            'attribute_id'  => ['nullable', 'integer', 'exists:inv_attributes,id', 'required_without_all:product_id,shipping_code,grn_no,print_status'],
            'grn_no'        => ['nullable', 'string', 'max:100', 'required_without_all:product_id,shipping_code,attribute_id,print_status'],
            'print_status'  => ['nullable', 'in:pending,printed'],
        ]);
    }

    /** Sealed pieces of confirmed GRNs matching the given filters. */
    private function pieceQuery(array $filters): Builder
    {
        $pending = ($filters['print_status'] ?? null) === 'pending';

        return GrnItemPiece::query()
            ->whereNotNull('piece_code')
            ->whereHas('grn', function (Builder $query) use ($filters): void {
                $query->where('status', GrnStatus::Confirmed);
                if (!empty($filters['shipping_code'])) {
                    $query->where('shipping_code', $filters['shipping_code']);
                }
                if (!empty($filters['grn_no'])) {
                    $query->where('grn_no', $filters['grn_no']);
                }
            })
            ->when(!empty($filters['product_id']), fn (Builder $query) => $query->where('product_id', (int) $filters['product_id']))
            ->when(!empty($filters['attribute_id']), fn (Builder $query) => $query->whereHas(
                'grnItem',
                fn (Builder $itemQuery) => $itemQuery->where('attribute_id', (int) $filters['attribute_id'])
            ))
            // A label is owed either because the roll arrived on a GRN nobody printed, or
            // because a sale cut it and the offcut's sticker does not exist yet — both are
            // simply pieces with no printed_at. Printing them stamps it (see pdf()), so the
            // queue empties itself.
            ->when($pending, fn (Builder $query) => $query->whereNull('printed_at'))
            ->when(($filters['print_status'] ?? null) === 'printed', fn (Builder $query) => $query->whereNotNull('printed_at'))
            ->with([
                'product:id,name,product_code,base_unit_type_id',
                'product.baseUnit:id,name,symbol',
                'batch:id,batch_no',
                'grn:id,grn_no,shipping_code',
                'grnItem:id,attribute_id',
                'grnItem.attribute:id,attribute_name',
                'parent:id,piece_code',
            ])
            // A work queue reads newest-first — the roll just cut is the one being held at
            // the cutting table. Everything else prints in GRN sheet order.
            ->when($pending, fn (Builder $query) => $query->orderByDesc('id'))
            ->unless($pending, fn (Builder $query) => $query
                ->orderBy('grn_id')
                ->orderBy('grn_item_id')
                ->orderBy('piece_no'));
    }

    /**
     * @param bool $withQr Screen preview needs the QR as an image; the PDF draws it itself.
     * @return Collection<int, array<string, mixed>>
     */
    private function buildLabels(Collection $pieces, bool $withQr): Collection
    {
        $qrs = $withQr ? $this->qr->matrices($pieces->pluck('piece_code')) : [];

        return $pieces->map(fn (GrnItemPiece $piece) => [
            'id'            => $piece->id,
            'piece_code'    => $piece->piece_code,
            'product_code'  => $piece->product?->product_code,
            'product_name'  => $piece->product?->name,
            'color'         => $piece->grnItem?->attribute?->attribute_name,
            'batch_no'      => $piece->batch?->batch_no,
            'roll_no'       => $piece->roll_no,
            'weight'        => $piece->weight,
            // An offcut's roll still wears the sticker of the roll it was cut from, which
            // now overstates its length. Naming that code tells the operator which sticker
            // to peel off, so one roll never carries two live QR codes.
            'replaces'      => $piece->parent?->piece_code,
            // Roll weights are sealed in the product's stocking UOM at GRN confirm,
            // so that is the unit the sticker must state — not the GRN's buying unit.
            'uom'           => $piece->product?->baseUnit?->symbol ?? $piece->product?->baseUnit?->name,
            'grn_no'        => $piece->grn?->grn_no,
            'shipping_code' => $piece->grn?->shipping_code,
            'printed_at'    => $piece->printed_at?->toDateTimeString(),
            'qr_data_uri'   => $withQr ? $this->qr->svgFromMatrix($qrs[$piece->piece_code]) : null,
        ])->values();
    }
}
