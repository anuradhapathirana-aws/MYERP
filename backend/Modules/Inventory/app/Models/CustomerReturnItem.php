<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Enums\ReturnCondition;
use Modules\Inventory\Enums\ReturnReason;

class CustomerReturnItem extends Model
{
    protected $table = 'inv_customer_return_items';

    protected $fillable = [
        'return_id',
        'invoice_item_id',
        'do_item_id',
        'product_id',
        'attribute_id',
        'unit_id',
        'quantity',
        'base_quantity',
        'unit_price',
        'discount',
        'tax',
        'line_total',
        'store_id',
        'location_id',
        'condition',
        'reason',
        'remarks',
    ];

    protected $casts = [
        'return_id'       => 'integer',
        'invoice_item_id' => 'integer',
        'do_item_id'      => 'integer',
        'product_id'      => 'integer',
        'attribute_id'    => 'integer',
        'unit_id'         => 'integer',
        'store_id'        => 'integer',
        'location_id'     => 'integer',
        'quantity'        => 'decimal:4',
        'base_quantity'   => 'decimal:6',
        'unit_price'      => 'decimal:4',
        'discount'        => 'decimal:4',
        'tax'             => 'decimal:4',
        'line_total'      => 'decimal:4',
        'condition'       => ReturnCondition::class,
        'reason'          => ReturnReason::class,
    ];

    public function customerReturn(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class, 'return_id');
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class, 'invoice_item_id');
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(CustomerReturnPiece::class, 'return_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'unit_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
