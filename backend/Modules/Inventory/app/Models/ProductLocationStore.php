<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductLocationStore extends Model
{
    protected $table = 'inv_product_location_stores';

    protected $fillable = [
        'product_id',
        'location_id',
        'store_id',
        'current_stock',
    ];

    protected $casts = [
        'product_id'    => 'integer',
        'location_id'   => 'integer',
        'store_id'      => 'integer',
        // Must match the column, decimal(20,6): a base-UOM balance can carry six places
        // (45.720046 m), and a shorter cast would round them off on every read.
        'current_stock' => 'decimal:6',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
