<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Endroid\QrCode\Bacon\MatrixFactory;
use Endroid\QrCode\QrCode;
use Illuminate\Support\Facades\Cache;

/**
 * The QR module grid of a roll's sticker, cached per roll.
 *
 * Encoding a QR costs ~30 ms in PHP — 25 s for a 798-roll GRN — and a roll's
 * code never changes, so each one is encoded once and kept forever. The cache
 * key is the encoded URL, so changing FRONTEND_URL simply encodes again.
 *
 * Labels draw the grid as vector squares (PieceLabelPdfRenderer) or an SVG
 * (screen preview). Rasterising PNGs and embedding them in a PDF was what made
 * a large GRN's labels outlive the server's request time limit.
 */
final class PieceQrCodeService
{
    private const CACHE_PREFIX = 'piece-qr:';

    /**
     * Black runs of each module row: [row, startColumn, length].
     *
     * @return array{modules: int, runs: list<array{0: int, 1: int, 2: int}>}
     */
    public function matrix(string $pieceCode): array
    {
        return $this->matrices([$pieceCode])[$pieceCode];
    }

    /**
     * Grids for many rolls at once — one cache round trip, which matters on the
     * database cache store when a PDF carries hundreds of rolls.
     *
     * @param  iterable<string> $pieceCodes
     * @return array<string, array{modules: int, runs: list<array{0: int, 1: int, 2: int}>}>
     */
    public function matrices(iterable $pieceCodes): array
    {
        $keys = [];
        foreach ($pieceCodes as $code) {
            $keys[$code] = $this->cacheKey($code);
        }

        if ($keys === []) {
            return [];
        }

        $cached  = Cache::many(array_values($keys));
        $missing = [];
        $result  = [];

        foreach ($keys as $code => $key) {
            $rows = $cached[$key] ?? null;

            if (!is_string($rows)) {
                $rows          = $this->encode($this->url($code));
                $missing[$key] = $rows;
            }

            $result[$code] = $this->runs($rows);
        }

        if ($missing !== []) {
            Cache::putMany($missing);
        }

        return $result;
    }

    /** Encode (or confirm cached) without building runs — for the warm-up command. */
    public function warm(iterable $pieceCodes): int
    {
        $keys = [];
        foreach ($pieceCodes as $code) {
            $keys[$this->cacheKey($code)] = $code;
        }

        if ($keys === []) {
            return 0;
        }

        $cached  = Cache::many(array_keys($keys));
        $missing = [];

        foreach ($keys as $key => $code) {
            if (!is_string($cached[$key] ?? null)) {
                $missing[$key] = $this->encode($this->url($code));
            }
        }

        if ($missing !== []) {
            Cache::putMany($missing);
        }

        return count($missing);
    }

    /** Self-contained SVG for an <img> on the screen preview. */
    public function svgDataUri(string $pieceCode): string
    {
        return $this->svgFromMatrix($this->matrix($pieceCode));
    }

    /** @param array{modules: int, runs: list<array{0: int, 1: int, 2: int}>} $qr */
    public function svgFromMatrix(array $qr, int $quiet = 1): string
    {
        $size = $qr['modules'] + 2 * $quiet;
        $path = '';

        foreach ($qr['runs'] as [$row, $col, $len]) {
            $path .= 'M' . ($col + $quiet) . ' ' . ($row + $quiet) . "h{$len}v1h-{$len}z";
        }

        $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$size} {$size}' shape-rendering='crispEdges'>"
            . "<rect width='{$size}' height='{$size}' fill='#fff'/><path d='{$path}'/></svg>";

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public function url(string $pieceCode): string
    {
        // Same URL the stickers have always carried: scanning opens the piece page.
        return config('app.frontend_url') . "/inventory/pieces/{$pieceCode}";
    }

    private function cacheKey(string $pieceCode): string
    {
        return self::CACHE_PREFIX . sha1($this->url($pieceCode));
    }

    /** Module rows as "0"/"1" strings joined by newlines — ~1 KB per roll in the cache. */
    private function encode(string $url): string
    {
        $matrix = (new MatrixFactory())->create(new QrCode(data: $url, margin: 0));
        $count  = $matrix->getBlockCount();
        $rows   = [];

        for ($row = 0; $row < $count; $row++) {
            $line = '';
            for ($col = 0; $col < $count; $col++) {
                $line .= $matrix->getBlockValue($row, $col) === 1 ? '1' : '0';
            }
            $rows[] = $line;
        }

        return implode("\n", $rows);
    }

    /** @return array{modules: int, runs: list<array{0: int, 1: int, 2: int}>} */
    private function runs(string $rows): array
    {
        $lines = explode("\n", $rows);
        $runs  = [];

        foreach ($lines as $row => $line) {
            $length = strlen($line);
            $col    = 0;

            while ($col < $length) {
                if ($line[$col] !== '1') {
                    $col++;
                    continue;
                }

                $start = $col;
                while ($col < $length && $line[$col] === '1') {
                    $col++;
                }
                $runs[] = [$row, $start, $col - $start];
            }
        }

        return ['modules' => count($lines), 'runs' => $runs];
    }
}
