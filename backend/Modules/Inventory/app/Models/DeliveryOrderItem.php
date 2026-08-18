<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryOrderItem extends Model
{
    protected $table = 'inv_delivery_order_items';

    protected $fillable = [
        'do_id',
        'so_item_id',
        'product_id',
        'unit_id',
        'attribute_id',
        'is_scanned',
        'quantity',
        'conversion_factor',
        'base_quantity',
        'remarks',
    ];

    protected $casts = [
        'do_id'        => 'integer',
        'so_item_id'   => 'integer',
        'product_id'   => 'integer',
        'unit_id'      => 'integer',
        'attribute_id' => 'integer',
        'is_scanned'   => 'boolean',
        'quantity'     => 'decimal:4',
        'conversion_factor' => 'decimal:10',
        'base_quantity'     => 'decimal:6',
    ];

    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class, 'do_id');
    }

    public function soItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'so_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'unit_id');
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(DeliveryOrderPiece::class, 'do_item_id');
    }
}
