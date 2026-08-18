<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends Model
{
    protected $table = 'inv_purchase_order_items';

    protected $fillable = [
        'po_id',
        'product_id',
        'unit_id',
        'attribute_id',
        'pr_item_id',
        'quantity_ordered',
        'quantity_received',
        'unit_price',
        'discount',
        'tax',
        'line_total',
        'remarks',
    ];

    protected $casts = [
        'po_id'             => 'integer',
        'product_id'        => 'integer',
        'unit_id'           => 'integer',
        'attribute_id'      => 'integer',
        'pr_item_id'        => 'integer',
        'quantity_ordered'  => 'decimal:4',
        'quantity_received' => 'decimal:4',
        'unit_price'        => 'decimal:4',
        'discount'          => 'decimal:4',
        'tax'               => 'decimal:4',
        'line_total'        => 'decimal:4',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'unit_id');
    }

    public function prItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class, 'pr_item_id');
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }

    public function grnItems(): HasMany
    {
        return $this->hasMany(GoodsReceivedNoteItem::class, 'po_item_id');
    }

    public function getRemainingQtyAttribute(): float
    {
        return max(0, (float) $this->quantity_ordered - (float) $this->quantity_received);
    }
}
