<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Dompdf\Adapter\CPDF;
use Dompdf\Dompdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Piece-label PDFs drawn straight onto dompdf's PDF canvas.
 *
 * The labels used to be Blade views: one HTML page per sticker, each with a PNG
 * QR code. dompdf needed ~0.2 s per label to lay out the HTML and decode the
 * PNGs, so a 224-roll print ran ~45 s and live killed the request ("Server
 * error"). Drawing text and the QR as vector squares costs a few ms per label —
 * a 798-roll GRN renders in seconds — and the vector QR prints sharper on the
 * thermal printer.
 *
 * Positions are in mm, copied from the Blade views they replace. The canvas
 * origin is the page's top-left, as in CSS.
 *
 * A label is the array built by PieceLabelController::buildLabels() / the GRN
 * label controller: piece_code, product_name, color, roll_no, weight, uom,
 * batch_no, replaces.
 */
final class PieceLabelPdfRenderer
{
    private const PT_PER_MM = 72 / 25.4;

    private const BLACK = [0, 0, 0];

    // A4 sheet colours (Tailwind slate / indigo / amber, as the old sheet used)
    private const SLATE_800  = [30 / 255, 41 / 255, 59 / 255];
    private const SLATE_600  = [71 / 255, 85 / 255, 105 / 255];
    private const SLATE_300  = [203 / 255, 213 / 255, 225 / 255];
    private const INDIGO_600 = [79 / 255, 70 / 255, 229 / 255];
    private const AMBER_700  = [180 / 255, 83 / 255, 9 / 255];

    private string $bold = '';
    private string $regular = '';
    private string $mono = '';
    private ?Dompdf $dompdf = null;

    public function __construct(private readonly PieceQrCodeService $qr)
    {
    }

    /**
     * The 2-up sticker roll in the TSC label printer: one PDF page is one row,
     * [38mm sticker][3mm gap][38mm sticker] x 25mm, the same roll on both
     * stickers — every product roll wears two identical QR tags.
     *
     * @param Collection<int, array<string, mixed>> $labels
     */
    public function stickerRoll(Collection $labels): string
    {
        $pdf = $this->canvas([0, 0, $this->mm(79), $this->mm(25)]);
        $qrs = $this->qr->matrices($labels->pluck('piece_code'));

        foreach ($labels->values() as $index => $label) {
            if ($index > 0) {
                $pdf->new_page();
            }

            // The row is 24.6mm of the 25mm page; nothing may bleed past it.
            $pdf->clipping_rectangle(0, 0, $this->mm(79), $this->mm(24.6));

            foreach ([0, 41] as $x) { // sticker 2 starts after 38mm sticker + 3mm gap
                $this->drawQr($pdf, $qrs[$label['piece_code']], $x + 1, 1, 18, 0.4);

                $pdf->text($this->mm($x + 1), $this->mm(19.6), Str::limit((string) $label['piece_code'], 38, ''), $this->mono, 4.5, self::BLACK);
                $pdf->text($this->mm($x + 1), $this->mm(21.9), Str::limit((string) ($label['product_name'] ?? ''), 28), $this->bold, 4.8, self::BLACK);

                $this->drawStickerDetails($pdf, $label, $x + 20.3);
            }

            $pdf->clipping_end();
        }

        return $pdf->output();
    }

    /**
     * A4 sheet of a whole GRN: 3 labels per row in dashed cut-out cells, the
     * GRN title on every page.
     *
     * @param Collection<int, array<string, mixed>> $labels
     */
    public function a4Sheet(string $grnNo, Collection $labels): string
    {
        $pdf = $this->canvas('a4');
        $qrs = $this->qr->matrices($labels->pluck('piece_code'));

        // Measured from the old sheet: edge-to-edge 70mm cells under a title band.
        $top     = 8.5;
        $cellW   = 70.0;
        $cellH   = 42.0;               // fits QR + code + 4 detail lines + replaces
        $perPage = 3 * (int) floor((297 - $top) / $cellH);

        foreach ($labels->values()->chunk($perPage) as $pageIndex => $pageLabels) {
            if ($pageIndex > 0) {
                $pdf->new_page();
            }

            $pdf->text($this->mm(0), $this->mm(1.4), "GRN {$grnNo} — Piece Labels", $this->bold, 10, self::INDIGO_600);

            // Pad the last row with empty cells, as the old table did.
            $slots = (int) (ceil($pageLabels->count() / 3) * 3);

            for ($slot = 0; $slot < $slots; $slot++) {
                $x = ($slot % 3) * $cellW;
                $y = $top + intdiv($slot, 3) * $cellH;

                $pdf->rectangle($this->mm($x), $this->mm($y), $this->mm($cellW), $this->mm($cellH), self::SLATE_300, 0.75, [3, 2]);

                $label = $pageLabels->values()->get($slot);
                if ($label !== null) {
                    $this->drawA4Cell($pdf, $label, $qrs[$label['piece_code']], $x, $y, $cellW, $cellH);
                }
            }
        }

        return $pdf->output();
    }

    /** Colour / Roll / Wt / Batch column beside the sticker's QR (17.2 x 18mm box). */
    private function drawStickerDetails(CPDF $pdf, array $label, float $xMm): void
    {
        // Truncated to what 6pt bold fits across 17.2mm (~14 characters with the
        // prefix), and clipped to the box like the old view's overflow:hidden.
        $lines = array_values(array_filter([
            !empty($label['color']) ? Str::limit((string) $label['color'], 13) : null,
            !empty($label['roll_no']) ? 'Roll: ' . Str::limit((string) $label['roll_no'], 8, '') : null,
            $label['weight'] !== null ? 'Wt: ' . number_format((float) $label['weight'], 2) . ' ' . $label['uom'] : null,
            !empty($label['batch_no']) ? 'B: ' . Str::limit((string) $label['batch_no'], 9, '') : null,
        ]));

        $pdf->clipping_rectangle($this->mm($xMm), $this->mm(1), $this->mm(17.2), $this->mm(18));

        // 3.15mm per 6pt line (as dompdf laid out the old view), plus a 0.6mm gap
        // above every line except the colour, which sits flush with the QR's top.
        $y = !empty($label['color']) ? 1.0 : 1.6;
        foreach ($lines as $text) {
            $pdf->text($this->mm($xMm), $this->mm($y), $text, $this->bold, 6, self::BLACK);
            $y += 3.75;
        }

        // Names the sticker this one supersedes, so the operator peels the stale
        // one off. Plain "REPL": DejaVu Sans Mono has no glyph for an arrow.
        if (!empty($label['replaces'])) {
            $pdf->text($this->mm($xMm), $this->mm($y + 0.3), 'REPL ' . $label['replaces'], $this->mono, 4.2, self::BLACK);
        }

        $pdf->clipping_end();
    }

    /** @param array{modules: int, runs: list<array{0: int, 1: int, 2: int}>} $qr */
    private function drawA4Cell(CPDF $pdf, array $label, array $qr, float $x, float $y, float $w, float $h): void
    {
        $padding = 1.6;
        $inner   = $w - 2 * $padding;
        $qrSize  = 23.8;  // the old sheet's 90px image
        $pitch   = 3.53;  // line spacing measured from the old sheet

        // [text, font, size, colour, extra gap above (mm)]
        $lines = [[(string) $label['piece_code'], $this->mono, 6.5, self::SLATE_800, 0.0]];
        $lines[] = [(string) ($label['product_name'] ?? ''), $this->regular, 6, self::SLATE_600, 0.0];
        if (!empty($label['roll_no'])) {
            $lines[] = ['Roll: ' . $label['roll_no'], $this->regular, 6, self::SLATE_600, 0.0];
        }
        if ($label['weight'] !== null) {
            $lines[] = ['Wt: ' . number_format((float) $label['weight'], 2) . ' ' . $label['uom'], $this->regular, 6, self::SLATE_600, 0.0];
        }
        if (!empty($label['batch_no'])) {
            $lines[] = ['Batch: ' . $label['batch_no'], $this->regular, 6, self::SLATE_600, 0.0];
        }
        if (!empty($label['replaces'])) {
            $lines[] = ['REPLACES ' . $label['replaces'], $this->mono, 5.5, self::AMBER_700, 0.5];
        }

        // Centred vertically, as the old table cell was.
        $contentH = $qrSize + 1.0 + count($lines) * $pitch + array_sum(array_column($lines, 4));
        $cursor   = $y + max($padding, ($h - $contentH) / 2);

        $this->drawQr($pdf, $qr, $x + ($w - $qrSize) / 2, $cursor, $qrSize, 0.5);
        $cursor += $qrSize + 1.0;

        foreach ($lines as [$text, $font, $size, $colour, $gap]) {
            $cursor += $gap;
            $text  = $this->fit($text, $font, $size, $inner);
            $width = $this->dompdf->getFontMetrics()->getTextWidth($text, $font, $size) / self::PT_PER_MM;
            $pdf->text($this->mm($x + ($w - $width) / 2), $this->mm($cursor), $text, $font, $size, $colour);
            $cursor += $pitch;
        }
    }

    /**
     * The QR as black squares, one rectangle per horizontal run of modules.
     *
     * @param array{modules: int, runs: list<array{0: int, 1: int, 2: int}>} $qr
     * @param float $quiet White margin around the code, in modules.
     */
    private function drawQr(CPDF $pdf, array $qr, float $xMm, float $yMm, float $sizeMm, float $quiet): void
    {
        $cell = $this->mm($sizeMm) / ($qr['modules'] + 2 * $quiet);
        $left = $this->mm($xMm) + $quiet * $cell;
        $top  = $this->mm($yMm) + $quiet * $cell;

        foreach ($qr['runs'] as [$row, $col, $len]) {
            // 0.1pt (0.04mm) overlap so anti-aliasing never shows seams between rows.
            $pdf->filled_rectangle($left + $col * $cell, $top + $row * $cell, $len * $cell + 0.1, $cell + 0.1, self::BLACK);
        }
    }

    /** Shortens $text with "..." until it fits $maxMm — the old cell wrapped instead. */
    private function fit(string $text, string $font, float $size, float $maxMm): string
    {
        $metrics = $this->dompdf->getFontMetrics();
        $max     = $maxMm * self::PT_PER_MM;

        if ($metrics->getTextWidth($text, $font, $size) <= $max) {
            return $text;
        }

        while (mb_strlen($text) > 1 && $metrics->getTextWidth($text . '...', $font, $size) > $max) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text) . '...';
    }

    /** @param string|array<int, float> $paper */
    private function canvas(string|array $paper): CPDF
    {
        $this->dompdf = new Dompdf(['defaultFont' => 'DejaVu Sans']);

        $fonts         = $this->dompdf->getFontMetrics();
        $this->bold    = (string) $fonts->getFont('DejaVu Sans', 'bold');
        $this->regular = (string) $fonts->getFont('DejaVu Sans', 'normal');
        $this->mono    = (string) $fonts->getFont('DejaVu Sans Mono', 'bold');

        return new CPDF($paper, 'portrait', $this->dompdf);
    }

    private function mm(float $mm): float
    {
        return $mm * self::PT_PER_MM;
    }
}
