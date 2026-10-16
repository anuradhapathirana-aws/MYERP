<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Enums\ProductServiceType;

class AttributeType extends Model
{
    protected $table = 'inv_attribute_types';

    protected $fillable = [
        'category_id',
        'product_service_type',
        'attribute_type_name',
        'description',
    ];

    protected $casts = [
        'category_id'          => 'integer',
        'product_service_type' => ProductServiceType::class,
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(Attribute::class, 'attribute_type_id');
    }

    /**
     * Ids of every Color/Colour type — one per category. Colour is identified by
     * type name, the same rule the document forms use.
     *
     * @return array<int, int>
     */
    public static function colourTypeIds(): array
    {
        return static::whereRaw('LOWER(attribute_type_name) IN (?, ?)', ['color', 'colour'])
            ->pluck('id')
            ->map('intval')
            ->all();
    }
}
