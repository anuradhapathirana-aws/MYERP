<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Support\NetPricingBackfill;

/**
 * Before Tax pricing switch — rehearse and verify.
 *
 *   1. BEFORE migrating:  php artisan inventory:net-pricing --dry-run
 *        writes nothing; CSVs of every price-list row and SO line that will change,
 *        with the old and new invoice unit prices (Tax and Non Tax).
 *   2. php artisan migrate --force
 *   3. AFTER migrating:   php artisan inventory:net-pricing --report
 *        CSV of price-list rows that were typed by hand (÷ VAT) for the client to check.
 *
 * CSVs land in storage/app/net-pricing/.
 */
class NetPricingReport extends Command
{
    protected $signature = 'inventory:net-pricing
                            {--dry-run : Show what the conversion migration will change (writes nothing)}
                            {--report : After migrating, list the hand-typed price-list rows to verify}';

    protected $description = 'Dry-run / report for the switch to Before Tax prices';

    public function handle(NetPricingBackfill $backfill): int
    {
        $dir = storage_path('app/net-pricing');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if ($this->option('report')) {
            return $this->report($dir);
        }

        if (! $this->option('dry-run')) {
            $this->error('Pass --dry-run (before migrating) or --report (after).');

            return self::FAILURE;
        }

        $prices = $backfill->planPriceList();
        $lines  = $backfill->planSalesOrderLines();

        $this->writeCsv("{$dir}/price-list.csv", $prices);
        $this->writeCsv("{$dir}/so-lines.csv", $lines);

        $this->info('Price list rows: ' . count($prices));
        $this->table(['Source', 'Rows'], collect($prices)->countBy('source')->map(fn ($n, $s) => [$s, $n])->values()->all());

        $open = collect($lines)->whereNotIn('status', ['completed', 'cancelled']);
        $this->info('SO lines: ' . count($lines) . ' (open: ' . $open->count() . ')');
        $this->table(['Source', 'Lines'], collect($lines)->countBy('source')->map(fn ($n, $s) => [$s, $n])->values()->all());

        $nonTaxDiff = collect($lines)->filter(fn ($l) => abs($l['old_non_tax_unit'] - $l['new_non_tax_unit']) > 0.001);
        $taxDiff    = collect($lines)->filter(fn ($l) => abs($l['old_tax_unit'] - $l['new_tax_unit']) > 0.001);
        $oldTax     = collect($lines)->filter(fn ($l) => $l['old_tax'] > 0);

        $this->line('');
        $this->line("Non Tax invoice unit price changes: {$nonTaxDiff->count()} line(s)");
        $this->line("Tax invoice unit price changes:     {$taxDiff->count()} line(s)");
        $this->line("SO lines that carried a Tax %:      {$oldTax->count()} line(s)");

        $changes = $nonTaxDiff->merge($taxDiff)->merge($oldTax)->unique('id')->values();
        if ($changes->isNotEmpty()) {
            $this->table(
                ['SO', 'Status', 'Product', 'Source', 'Old SO price', 'New Before Tax', 'VAT %', 'Non Tax old→new', 'Tax old→new', 'Old Tax %'],
                $changes->map(fn ($l) => [
                    $l['so_no'], $l['status'], $l['product'], $l['source'], $l['old_price'], $l['new_price'], $l['vat_pct'],
                    "{$l['old_non_tax_unit']} → {$l['new_non_tax_unit']}", "{$l['old_tax_unit']} → {$l['new_tax_unit']}", $l['old_tax'],
                ])->all(),
            );
        }

        $this->info("CSVs written to {$dir}");

        return self::SUCCESS;
    }

    private function report(string $dir): int
    {
        if (! Schema::hasColumn('inv_product_sales_channels', 'net_price_source')) {
            $this->error('Not migrated yet — run --dry-run instead.');

            return self::FAILURE;
        }

        $rows = collect();
        foreach (['inv_product_sales_channels' => 'product_id', 'inv_product_variant_prices' => 'variant_id'] as $table => $key) {
            $rows = $rows->merge(
                DB::table($table)->where('net_price_source', 'vat_stripped')
                    ->get(['id', $key, 'legacy_selling_price', 'selling_price'])
                    ->map(fn ($r) => ['table' => $table] + (array) $r),
            );
        }

        $this->writeCsv("{$dir}/verify-price-list.csv", $rows->all());
        $this->info("{$rows->count()} hand-typed price-list row(s) converted by ÷ VAT — check them: {$dir}/verify-price-list.csv");

        return self::SUCCESS;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function writeCsv(string $path, array $rows): void
    {
        $fh = fopen($path, 'w');
        if ($rows !== []) {
            fputcsv($fh, array_keys(reset($rows)));
            foreach ($rows as $row) {
                fputcsv($fh, array_values($row));
            }
        }
        fclose($fh);
    }
}
