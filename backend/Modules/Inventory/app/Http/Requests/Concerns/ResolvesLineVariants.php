<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\ProductVariant;

/**
 * Document lines of products with variants name the variant; the colour is
 * derived from it server-side (never trusted from input), so every colour
 * rule, report and PDF that reads attribute_id keeps working unchanged.
 *
 * Use: call prepareLineVariants() from prepareForValidation() and
 * validateLineVariants() from withValidator().
 */
trait ResolvesLineVariants
{
    /**
     * Lines linked to a source line (a GRN line's po_item_id) that send no
     * variant take the source line's — the PO already fixed it.
     */
    protected function inheritLineVariants(string $sourceKey, string $sourceTable, string $key = 'items'): void
    {
        $items = $this->input($key);
        if (! is_array($items)) {
            return;
        }

        $sourceIds = collect($items)
            ->filter(fn ($item) => is_array($item) && empty($item['variant_id']) && is_numeric($item[$sourceKey] ?? null))
            ->pluck($sourceKey)->map('intval')->unique()->values()->all();
        if ($sourceIds === []) {
            return;
        }

        $variantBySource = DB::table($sourceTable)->whereIn('id', $sourceIds)->whereNotNull('variant_id')->pluck('variant_id', 'id');

        foreach ($items as $i => $item) {
            if (is_array($item) && empty($item['variant_id']) && isset($variantBySource[(int) ($item[$sourceKey] ?? 0)])) {
                $items[$i]['variant_id'] = (int) $variantBySource[(int) $item[$sourceKey]];
            }
        }

        $this->merge([$key => $items]);
    }

    /** Overwrite each variant line's attribute_id with the variant's colour. */
    protected function prepareLineVariants(string $key = 'items'): void
    {
        $items = $this->input($key);
        if (! is_array($items)) {
            return;
        }

        $variantIds = collect($items)->pluck('variant_id')->filter(fn ($v) => is_numeric($v))->map('intval')->unique()->values()->all();
        if ($variantIds === []) {
            return;
        }

        $variants = ProductVariant::with('values')->whereIn('id', $variantIds)->get()->keyBy('id');

        foreach ($items as $i => $item) {
            $variant = is_array($item) && is_numeric($item['variant_id'] ?? null)
                ? $variants->get((int) $item['variant_id'])
                : null;

            if ($variant !== null) {
                $items[$i]['attribute_id'] = $variant->colourAttributeId();
            }
        }

        $this->merge([$key => $items]);
    }

    /**
     * A product with active variants needs one on every line; a variant must
     * belong to its line's product and be active (an inactive one may stay on
     * a line that already had it — editing an old draft).
     *
     * @param array<int, int> $allowInactive variant ids already on the document
     */
    protected function validateLineVariants(Validator $validator, string $key = 'items', array $allowInactive = []): void
    {
        $items = (array) $this->input($key, []);

        $productIds = collect($items)->pluck('product_id')->filter()->map('intval')->unique()->values()->all();
        $withVariants = ProductVariant::productIdsWithVariants($productIds);

        $variantIds = collect($items)->pluck('variant_id')->filter(fn ($v) => is_numeric($v))->map('intval')->unique()->values()->all();
        $variants = ProductVariant::whereIn('id', $variantIds)->get(['id', 'product_id', 'is_active'])->keyBy('id');

        foreach ($items as $i => $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $variantId = is_numeric($item['variant_id'] ?? null) ? (int) $item['variant_id'] : null;

            if ($variantId === null) {
                if (in_array($productId, $withVariants, true)) {
                    $validator->errors()->add("{$key}.{$i}.variant_id", 'Select the variant (e.g. colour / design / size) for this item.');
                }
                continue;
            }

            $variant = $variants->get($variantId);

            if ($variant === null || (int) $variant->product_id !== $productId) {
                $validator->errors()->add("{$key}.{$i}.variant_id", 'This variant does not belong to the selected product.');
            } elseif (! $variant->is_active && ! in_array($variantId, $allowInactive, true)) {
                $validator->errors()->add("{$key}.{$i}.variant_id", 'This variant is inactive.');
            }
        }
    }
}
