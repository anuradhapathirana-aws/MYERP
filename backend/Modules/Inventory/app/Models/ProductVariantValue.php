<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariantValue extends Model
{
    protected $table = 'inv_product_variant_values';

    protected $fillable = [
        'variant_id',
        'attribute_type_id',
        'attribute_id',
    ];

    protected $casts = [
        'variant_id'        => 'integer',
        'attribute_type_id' => 'integer',
        'attribute_id'      => 'integer',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }

    public function attributeType(): BelongsTo
    {
        return $this->belongsTo(AttributeType::class, 'attribute_type_id');
    }
}
