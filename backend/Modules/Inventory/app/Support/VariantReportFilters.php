<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use Illuminate\Database\Query\Builder;

/**
 * Report filters shared by the stock and sales reports:
 *
 *   item_type        — 'roll' (fabric) or 'quantity' (finished goods), the product's
 *                      stock tracking. Product Type is free text in the data, so the
 *                      tracking mode is the reliable split.
 *   attribute_values — attribute value ids that must ALL match (Size = M AND Design =
 *                      Floral). A row matches a value when its variant carries it, or —
 *                      roll / colour-only rows — when its own colour is that value.
 */
final class VariantReportFilters
{
    public const ITEM_TYPES = ['roll', 'quantity'];

    /**
     * @param array<string, mixed> $filters
     * @param string $productAlias   alias of inv_products in the query (for item_type)
     * @param string $variantColumn  qualified variant_id column of the row
     * @param string $attributeColumn qualified attribute_id (colour) column of the row
     */
    public static function apply(Builder $query, array $filters, string $productAlias, string $variantColumn, string $attributeColumn): Builder
    {
        $itemType = $filters['item_type'] ?? null;
        if (in_array($itemType, self::ITEM_TYPES, true)) {
            $query->where("{$productAlias}.stock_tracking", $itemType);
        }

        foreach (self::attributeValueIds($filters) as $valueId) {
            $query->where(fn (Builder $q) => $q
                ->whereExists(fn (Builder $e) => $e
                    ->selectRaw('1')
                    ->from('inv_product_variant_values as vf')
                    ->whereColumn('vf.variant_id', $variantColumn)
                    ->where('vf.attribute_id', $valueId))
                ->orWhere($attributeColumn, $valueId));
        }

        return $query;
    }

    /** @return array<int, int> */
    public static function attributeValueIds(array $filters): array
    {
        $raw = $filters['attribute_values'] ?? [];
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        return array_values(array_unique(array_filter(array_map('intval', (array) $raw), fn (int $id) => $id > 0)));
    }

    /**
     * Validation rules every report request adds. attribute_values may arrive as an
     * array or as the comma list the filter panels send ("12,31") — attributeValueIds()
     * keeps only positive integers either way.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'item_type'        => ['nullable', 'in:roll,quantity'],
            'attribute_values' => ['nullable'],
        ];
    }

    /** The two filters as read from a report request. @return array{item_type:?string, attribute_values:array<int,int>} */
    public static function fromRequest(\Illuminate\Http\Request $request): array
    {
        return [
            'item_type'        => in_array($request->input('item_type'), self::ITEM_TYPES, true) ? $request->input('item_type') : null,
            'attribute_values' => self::attributeValueIds(['attribute_values' => $request->input('attribute_values', [])]),
        ];
    }

    /** "Size: M, Design: Floral" for a report header — null when no value is filtered. */
    public static function attributeValuesLabel(array $filters): ?string
    {
        $ids = self::attributeValueIds($filters);
        if ($ids === []) {
            return null;
        }

        return \Illuminate\Support\Facades\DB::table('inv_attributes as a')
            ->join('inv_attribute_types as t', 't.id', '=', 'a.attribute_type_id')
            ->whereIn('a.id', $ids)
            ->orderBy('t.id')
            ->get(['t.attribute_type_name', 'a.attribute_name'])
            ->map(fn ($r) => "{$r->attribute_type_name}: {$r->attribute_name}")
            ->implode(', ');
    }

    /** Human label for a report header. */
    public static function itemTypeLabel(?string $itemType): ?string
    {
        return match ($itemType) {
            'roll'     => 'Fabric (Roll)',
            'quantity' => 'Finished Good (Qty)',
            default    => null,
        };
    }
}
