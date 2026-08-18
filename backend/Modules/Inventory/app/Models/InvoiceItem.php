<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    protected $table = 'inv_invoice_items';

    protected $fillable = [
        'invoice_id',
        'so_item_id',
        'do_item_id',
        'product_id',
        'unit_id',
        'price_unit_id',
        'attribute_id',
        'quantity',
        'unit_price',
        'discount',
        'tax',
        'line_total',
        'remarks',
    ];

    protected $casts = [
        'invoice_id'   => 'integer',
        'so_item_id'   => 'integer',
        'do_item_id'   => 'integer',
        'product_id'   => 'integer',
        'unit_id'      => 'integer',
        'price_unit_id' => 'integer',
        'attribute_id' => 'integer',
        'quantity'     => 'decimal:4',
        'unit_price'   => 'decimal:4',
        'discount'     => 'decimal:4',
        'tax'          => 'decimal:4',
        'line_total'   => 'decimal:4',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function soItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'so_item_id');
    }

    public function doItem(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrderItem::class, 'do_item_id');
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
}
