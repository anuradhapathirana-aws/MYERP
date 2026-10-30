<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Services\PieceQrCodeService;

/**
 * Pre-encodes the QR code of every sealed roll into the cache.
 *
 * Encoding costs ~30 ms per roll. Labels encode on demand anyway, but a roll's
 * first print then pays for it — 798 rolls is ~25 s inside one request. Run once
 * after deploying the vector label renderer (or after changing FRONTEND_URL,
 * which changes every code) so the first big print is as fast as the rest.
 *
 * Usage:
 *   php artisan inventory:warm-piece-qr
 */
class WarmPieceQrCodes extends Command
{
    protected $signature = 'inventory:warm-piece-qr';

    protected $description = 'Pre-encode piece label QR codes into the cache';

    public function handle(PieceQrCodeService $qr): int
    {
        $total   = GrnItemPiece::whereNotNull('piece_code')->count();
        $encoded = 0;

        $this->info("Checking {$total} piece QR codes…");
        $bar = $this->output->createProgressBar($total);

        GrnItemPiece::whereNotNull('piece_code')
            ->select(['id', 'piece_code'])
            ->chunkById(200, function ($pieces) use ($qr, $bar, &$encoded): void {
                $encoded += $qr->warm($pieces->pluck('piece_code'));
                $bar->advance($pieces->count());
            });

        $bar->finish();
        $this->newLine();
        $this->info("Done: {$encoded} encoded, " . ($total - $encoded) . ' already cached.');

        return self::SUCCESS;
    }
}
