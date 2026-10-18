<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Tables back-filled by (product_id, attribute_id) → variant_id. */
    private const TABLES = [
        'inv_stock_transactions',
        'inv_purchase_request_items',
        'inv_purchase_order_items',
        'inv_goods_received_note_items',
        'inv_sales_order_items',
        'inv_delivery_order_items',
        'inv_invoice_items',
        'inv_proforma_invoice_items',
        'inv_customer_return_items',
        'inv_costing_items',
        'inv_stock_reconciliations',
    ];

    /**
     * Turn every quantity-tracked product's existing colours into single-value
     * variants, then stamp variant_id on the ledger and every document line
     * that carries that colour — so balances keyed by variant equal today's
     * balances keyed by colour, row for row.
     *
     *  - Colours come from the product's Colour attributes AND from whatever
     *    attribute_id its ledger / lines actually carry.
     *  - Rows with no colour (or a colour whose master row is gone) go to a
     *    "Default" variant — but only when the product has other variants;
     *    a product with no colour at all gets no variants and stays as today.
     *  - Roll-tracked products are not touched.
     *
     * Idempotent: existing combinations are reused and only NULL variant_ids
     * are written, so a re-run (or a partial run) is safe.
     */
    public function up(): void
    {
        $products = DB::table('inv_products')
            ->where('stock_tracking', 'quantity')
            ->get(['id', 'product_code']);

        if ($products->isEmpty()) {
            return;
        }

        $productIds = $products->pluck('id')->map('intval')->all();

        $colourTypeIds = DB::table('inv_attribute_types')
            ->whereRaw('LOWER(attribute_type_name) IN (?, ?)', ['color', 'colour'])
            ->pluck('id')
            ->map('intval')
            ->all();

        // product_id => [attribute_id|null => true]
        $used = [];

        foreach (DB::table('inv_product_attributes')
            ->whereIn('product_id', $productIds)
            ->whereIn('attribute_type_id', $colourTypeIds)
            ->get(['product_id', 'attribute_id']) as $row) {
            if ($row->attribute_id !== null) {
                $used[(int) $row->product_id][(int) $row->attribute_id] = true;
            }
        }

        foreach (self::TABLES as $table) {
            foreach (DB::table($table)
                ->whereIn('product_id', $productIds)
                ->distinct()
                ->get(['product_id', 'attribute_id']) as $row) {
                $used[(int) $row->product_id][$row->attribute_id === null ? 'none' : (int) $row->attribute_id] = true;
            }
        }

        $attributes = DB::table('inv_attributes')
            ->get(['id', 'attribute_type_id', 'attribute_name'])
            ->keyBy('id');

        $created  = 0;
        $stamped  = 0;
        $takenSku = [];

        foreach ($products as $product) {
            $keys = array_keys($used[(int) $product->id] ?? []);

            $realIds = array_values(array_filter($keys, fn ($k) => $k !== 'none' && $attributes->has($k)));
            // A colour whose attribute row was deleted cannot be a variant value: it joins Default.
            $orphanIds = array_values(array_filter($keys, fn ($k) => $k !== 'none' && ! $attributes->has($k)));
            $needsDefault = in_array('none', $keys, true) || $orphanIds !== [];

            if ($realIds === []) {
                // No colour at all: no variants, the product keeps working exactly as today.
                continue;
            }

            DB::transaction(function () use ($product, $realIds, $orphanIds, $needsDefault, $attributes, &$created, &$stamped, &$takenSku): void {
                foreach ($realIds as $attributeId) {
                    $attribute = $attributes->get($attributeId);

                    // A colour found only on the ledger/lines becomes one of the product's
                    // attributes too — a variant may only be built from assigned values.
                    $assigned = DB::table('inv_product_attributes')
                        ->where('product_id', $product->id)
                        ->where('attribute_id', $attributeId)
                        ->exists();
                    if (! $assigned) {
                        DB::table('inv_product_attributes')->insert([
                            'product_id'        => $product->id,
                            'attribute_type_id' => $attribute->attribute_type_id,
                            'attribute_id'      => $attributeId,
                            'created_at'        => now(),
                            'updated_at'        => now(),
                        ]);
                    }

                    $variantId = $this->variantFor(
                        (int) $product->id,
                        (string) $product->product_code,
                        (string) $attributeId,
                        (string) $attribute->attribute_name,
                        [['attribute_type_id' => (int) $attribute->attribute_type_id, 'attribute_id' => (int) $attributeId]],
                        $created,
                        $takenSku,
                    );
                    $stamped += $this->stamp((int) $product->id, $attributeId, $variantId);
                }

                if ($needsDefault) {
                    $variantId = $this->variantFor((int) $product->id, (string) $product->product_code, '', 'Default', [], $created, $takenSku);
                    $stamped += $this->stamp((int) $product->id, null, $variantId);

                    foreach ($orphanIds as $orphanId) {
                        $stamped += $this->stamp((int) $product->id, (int) $orphanId, $variantId);
                    }
                }
            });
        }

        Log::info("Product variants back-fill: {$created} variants created, {$stamped} rows stamped.");
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::table($table)->whereNotNull('variant_id')->update(['variant_id' => null]);
        }

        DB::table('inv_product_variant_prices')->delete();
        DB::table('inv_product_variant_values')->delete();
        DB::table('inv_product_variants')->delete();
    }

    /**
     * Existing variant for this combination, or a new one.
     *
     * @param  array<int, array{attribute_type_id:int, attribute_id:int}>  $values
     * @param  array<string, true>  $takenSku
     */
    private function variantFor(int $productId, string $productCode, string $hash, string $label, array $values, int &$created, array &$takenSku): int
    {
        $existing = DB::table('inv_product_variants')
            ->where('product_id', $productId)
            ->where('combination_hash', $hash)
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        $segment = $hash === '' ? 'STD' : (Str::upper(Str::limit(preg_replace('/[^A-Za-z0-9]/', '', $label) ?: $hash, 6, '')));
        $sku     = $this->uniqueSku(($productCode !== '' ? $productCode : 'P' . $productId) . '-' . $segment, $takenSku);
        $now     = now();

        $variantId = (int) DB::table('inv_product_variants')->insertGetId([
            'product_id'       => $productId,
            'sku'              => $sku,
            'barcode'          => null,
            'variant_label'    => $label,
            'combination_hash' => $hash,
            'is_active'        => true,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        foreach ($values as $value) {
            DB::table('inv_product_variant_values')->insert($value + [
                'variant_id' => $variantId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $created++;

        return $variantId;
    }

    /** @param array<string, true> $takenSku */
    private function uniqueSku(string $base, array &$takenSku): string
    {
        $base = Str::limit($base, 72, '');
        $sku  = $base;
        $n    = 2;

        while (isset($takenSku[$sku]) || DB::table('inv_product_variants')->where('sku', $sku)->exists()) {
            $sku = "{$base}-{$n}";
            $n++;
        }

        $takenSku[$sku] = true;

        return $sku;
    }

    private function stamp(int $productId, ?int $attributeId, int $variantId): int
    {
        $count = 0;

        foreach (self::TABLES as $table) {
            $count += DB::table($table)
                ->where('product_id', $productId)
                ->when(
                    $attributeId === null,
                    fn ($q) => $q->whereNull('attribute_id'),
                    fn ($q) => $q->where('attribute_id', $attributeId),
                )
                ->whereNull('variant_id')
                ->update(['variant_id' => $variantId]);
        }

        return $count;
    }
};
