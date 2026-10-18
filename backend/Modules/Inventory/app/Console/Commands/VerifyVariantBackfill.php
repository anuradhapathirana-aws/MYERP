<?php

declare(strict_types=1);

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\AttributeType;
use Modules\Inventory\Models\ProductVariant;

/**
 * Proves the product-variant back-fill changed no stock balance.
 *
 *   1. BEFORE migrating:  php artisan inventory:verify-variants --snapshot=storage/app/variant-snapshot.json
 *   2. php artisan migrate --force
 *   3. AFTER migrating:   php artisan inventory:verify-variants --compare=storage/app/variant-snapshot.json
 *
 * Without options it only runs the integrity checks (safe to run any time).
 * Exit code 0 = PASS, 1 = FAIL.
 */
class VerifyVariantBackfill extends Command
{
    protected $signature = 'inventory:verify-variants
                            {--snapshot= : Write current balances (product × colour × store × location) to this JSON file}
                            {--compare= : Compare current balances with a snapshot file and run the variant checks}';

    protected $description = 'Snapshot / verify stock balances around the product-variant back-fill';

    public function handle(): int
    {
        if ($path = $this->option('snapshot')) {
            $balances = $this->colourBalances();
            file_put_contents(base_path($path), json_encode([
                'taken_at' => now()->toIso8601String(),
                'balances' => $balances,
                'rows'     => DB::table('inv_stock_transactions')->count(),
            ], JSON_PRETTY_PRINT));
            $this->info('Snapshot written: ' . count($balances) . ' balances → ' . $path);

            return self::SUCCESS;
        }

        $failures = 0;

        if ($path = $this->option('compare')) {
            $failures += $this->compareWithSnapshot(base_path($path));
        }

        if (! DB::getSchemaBuilder()->hasTable('inv_product_variants')) {
            $this->warn('Variant tables not migrated yet — only the snapshot comparison was run.');

            return $failures === 0 ? self::SUCCESS : self::FAILURE;
        }

        $failures += $this->checkNoUnstampedRows();
        $failures += $this->checkVariantsMatchProduct();
        $failures += $this->checkVariantMatchesColour();
        $this->printVariantBalances();

        $failures === 0
            ? $this->info('PASS — balances unchanged and every variant reference is consistent.')
            : $this->error("FAIL — {$failures} problem(s) found. Do NOT continue; restore the backup.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, float> "product|colour|store|location" => balance */
    private function colourBalances(): array
    {
        return DB::table('inv_stock_transactions')
            ->selectRaw('product_id, attribute_id, store_id, location_id, SUM(qty_in) - SUM(qty_out) AS balance')
            ->groupBy('product_id', 'attribute_id', 'store_id', 'location_id')
            ->get()
            ->mapWithKeys(fn ($r) => [
                "{$r->product_id}|" . ($r->attribute_id ?? '-') . '|' . ($r->store_id ?? '-') . '|' . ($r->location_id ?? '-')
                    => round((float) $r->balance, 6),
            ])
            ->all();
    }

    private function compareWithSnapshot(string $file): int
    {
        if (! is_file($file)) {
            $this->error("Snapshot not found: {$file}");

            return 1;
        }

        $before = json_decode((string) file_get_contents($file), true)['balances'] ?? [];
        $after  = $this->colourBalances();
        $diff   = [];

        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $b = (float) ($before[$key] ?? 0);
            $a = (float) ($after[$key] ?? 0);
            if (abs($a - $b) > 0.000001) {
                $diff[] = [$key, $b, $a];
            }
        }

        if ($diff === []) {
            $this->info('✓ ' . count($before) . ' colour balances identical to the snapshot.');

            return 0;
        }

        $this->error('✗ Balances changed:');
        $this->table(['product|colour|store|location', 'before', 'after'], $diff);

        return count($diff);
    }

    /** A quantity product that has variants must have variant_id on every ledger row. */
    private function checkNoUnstampedRows(): int
    {
        $count = DB::table('inv_stock_transactions as st')
            ->join('inv_products as p', 'p.id', '=', 'st.product_id')
            ->where('p.stock_tracking', 'quantity')
            ->whereNull('st.variant_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('inv_product_variants as v')->whereColumn('v.product_id', 'st.product_id'))
            ->count();

        $count === 0
            ? $this->info('✓ Every ledger row of a product with variants carries its variant.')
            : $this->error("✗ {$count} ledger rows of products with variants have no variant_id.");

        return $count;
    }

    /** variant_id must always point at a variant of the row's own product. */
    private function checkVariantsMatchProduct(): int
    {
        $bad = 0;
        foreach (ProductVariant::REFERENCING_TABLES as $table) {
            $n = DB::table("{$table} as t")
                ->leftJoin('inv_product_variants as v', 'v.id', '=', 't.variant_id')
                ->whereNotNull('t.variant_id')
                ->where(fn ($q) => $q->whereNull('v.id')->orWhereColumn('v.product_id', '!=', 't.product_id'))
                ->count();
            if ($n > 0) {
                $this->error("✗ {$table}: {$n} rows point at a missing variant or another product's variant.");
                $bad += $n;
            }
        }

        if ($bad === 0) {
            $this->info('✓ Every variant_id belongs to its row\'s product.');
        }

        return $bad;
    }

    /** A colour variant's ledger rows must all carry that same colour. */
    private function checkVariantMatchesColour(): int
    {
        $colourTypeIds = AttributeType::colourTypeIds();

        $rows = DB::table('inv_stock_transactions as st')
            ->join('inv_product_variant_values as vv', 'vv.variant_id', '=', 'st.variant_id')
            ->whereIn('vv.attribute_type_id', $colourTypeIds)
            ->where(fn ($q) => $q->whereNull('st.attribute_id')->orWhereColumn('st.attribute_id', '!=', 'vv.attribute_id'))
            ->count();

        $rows === 0
            ? $this->info('✓ Every colour variant\'s movements carry that colour.')
            : $this->error("✗ {$rows} ledger rows carry a colour different from their variant's colour.");

        return $rows;
    }

    private function printVariantBalances(): void
    {
        $rows = DB::table('inv_stock_transactions as st')
            ->join('inv_product_variants as v', 'v.id', '=', 'st.variant_id')
            ->join('inv_products as p', 'p.id', '=', 'st.product_id')
            ->selectRaw('p.product_code, p.name, v.sku, v.variant_label, SUM(st.qty_in) - SUM(st.qty_out) AS balance')
            ->groupBy('p.product_code', 'p.name', 'v.sku', 'v.variant_label')
            ->orderBy('p.product_code')
            ->orderBy('v.sku')
            ->get()
            ->map(fn ($r) => [$r->product_code, $r->name, $r->sku, $r->variant_label, round((float) $r->balance, 4)])
            ->all();

        $this->line('');
        $this->line('Variant balances (all stores):');
        $this->table(['Product', 'Name', 'SKU', 'Variant', 'Balance'], $rows);
    }
}
