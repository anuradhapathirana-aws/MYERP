<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds inv_product_location_stores.current_stock from the transaction ledger.
 *
 * Run once to repair historical data where the balance cache was not updated
 * (e.g., GRNs confirmed before the store_id guard was removed from confirm()).
 * Going forward, every GRN confirmation atomically updates current_stock, so
 * this command is only needed for a one-time migration repair.
 *
 * Usage:
 *   php artisan inventory:recalculate-stock-balances
 *   php artisan inventory:recalculate-stock-balances --dry-run
 */
class RecalculateStockBalances extends Command
{
    protected $signature = 'inventory:recalculate-stock-balances
                            {--dry-run : Show what would be updated without writing}';

    protected $description = 'Rebuild inv_product_location_stores.current_stock from the transaction ledger';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('[DRY RUN] No data will be written.');
        }

        // ── 1. Aggregate ledger by product / location / store ─────────────────
        $this->info('Reading stock transactions…');

        $balances = DB::table('inv_stock_transactions')
            ->selectRaw('
                product_id,
                location_id,
                store_id,
                SUM(qty_in) - SUM(qty_out) AS balance
            ')
            ->whereNotNull('location_id')
            ->whereNotNull('store_id')
            ->groupBy('product_id', 'location_id', 'store_id')
            ->get();

        $orphaned = DB::table('inv_stock_transactions')
            ->where(function ($q): void {
                $q->whereNull('location_id')->orWhereNull('store_id');
            })
            ->count();

        $this->info("Found {$balances->count()} product/location/store balance combinations.");

        if ($orphaned > 0) {
            $this->warn(
                "{$orphaned} transaction row(s) have NULL location_id or store_id and will be skipped. " .
                'These were written before location+store became required on GRNs.'
            );
        }

        if ($balances->isEmpty()) {
            $this->info('Nothing to recalculate.');
            return self::SUCCESS;
        }

        // ── 2. Upsert each balance into the cache table ───────────────────────
        $bar = $this->output->createProgressBar($balances->count());
        $bar->start();

        $updated  = 0;
        $inserted = 0;
        $now      = now()->toDateTimeString();

        foreach ($balances as $row) {
            $stock = max(0.0, (float) $row->balance);

            $exists = DB::table('inv_product_location_stores')
                ->where('product_id',  $row->product_id)
                ->where('location_id', $row->location_id)
                ->where('store_id',    $row->store_id)
                ->exists();

            if ($isDryRun) {
                $bar->advance();
                $exists ? $updated++ : $inserted++;
                continue;
            }

            if ($exists) {
                DB::table('inv_product_location_stores')
                    ->where('product_id',  $row->product_id)
                    ->where('location_id', $row->location_id)
                    ->where('store_id',    $row->store_id)
                    ->update([
                        'current_stock' => $stock,
                        'updated_at'    => $now,
                    ]);
                $updated++;
            } else {
                DB::table('inv_product_location_stores')->insert([
                    'product_id'    => $row->product_id,
                    'location_id'   => $row->location_id,
                    'store_id'      => $row->store_id,
                    'current_stock' => $stock,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
                $inserted++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $prefix = $isDryRun ? '[DRY RUN] Would have ' : '';
        $this->info("{$prefix}Updated : {$updated} existing balance records.");
        $this->info("{$prefix}Inserted: {$inserted} new balance records.");
        $this->info('Done.');

        return self::SUCCESS;
    }
}
