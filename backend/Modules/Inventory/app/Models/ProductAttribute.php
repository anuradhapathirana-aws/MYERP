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
}
