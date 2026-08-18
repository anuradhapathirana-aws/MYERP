<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrderItem extends Model
{
    protected $table = 'inv_sales_order_items';

    protected $fillable = [
        'so_id',
        'product_id',
        'unit_id',
        'price_unit_id',
        'attribute_id',
        'is_scanned',
        'quantity',
        'quantity_delivered',
        'conversion_factor',
        'base_quantity',
        'unit_price',
        'discount',
        'tax',
        'line_total',
        'remarks',
    ];

    protected $casts = [
        'so_id'        => 'integer',
        'product_id'   => 'integer',
        'unit_id'      => 'integer',
        'price_unit_id' => 'integer',
        'attribute_id' => 'integer',
        'is_scanned'   => 'boolean',
        'quantity'     => 'decimal:4',
        'quantity_delivered' => 'decimal:4',
        'conversion_factor'  => 'decimal:10',
        'base_quantity'      => 'decimal:6',
        'unit_price'   => 'decimal:4',
        'discount'     => 'decimal:4',
        'tax'          => 'decimal:4',
        'line_total'   => 'decimal:4',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'so_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'unit_id');
    }

    /** The unit the price is quoted per — may differ from unit_id (the quantity's unit). */
    public function priceUnit(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'price_unit_id');
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(SalesOrderPiece::class, 'so_item_id');
    }
}
