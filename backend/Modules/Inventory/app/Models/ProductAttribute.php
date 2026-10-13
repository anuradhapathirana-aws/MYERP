<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class ProductAttribute extends Model
{
    protected $table = 'inv_product_attributes';

    protected $fillable = [
        'product_id',
        'attribute_type_id',
        'attribute_id',
    ];

    protected $casts = [
        'product_id'        => 'integer',
        'attribute_type_id' => 'integer',
        'attribute_id'      => 'integer',
    ];

    /**
     * Of the given product ids, the ones that offer a Color/Colour attribute —
     * those must carry a colour on every document line.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, int>
     */
    public static function colourProductIds(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $colourTypeIds = AttributeType::whereRaw('LOWER(attribute_type_name) IN (?, ?)', ['color', 'colour'])
            ->pluck('id');

        return static::whereIn('product_id', $productIds)
            ->whereIn('attribute_type_id', $colourTypeIds)
            ->distinct()
            ->pluck('product_id')
            ->map('intval')
            ->all();
    }
}
