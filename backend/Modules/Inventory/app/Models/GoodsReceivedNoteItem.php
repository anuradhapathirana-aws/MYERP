<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceivedNoteItem extends Model
{
    protected $table = 'inv_goods_received_note_items';

    protected $fillable = [
        'grn_id',
        'po_item_id',
        'product_id',
        'unit_id',
        'attribute_id',
        'quantity_ordered',
        'quantity_received',
        'conversion_factor',
        'base_quantity',
        'no_of_pieces',
        'unit_price',
        'discount',
        'tax',
        'line_total',
        'batch_no',
        'expiry_date',
    ];

    protected $casts = [
        'grn_id'            => 'integer',
        'po_item_id'        => 'integer',
        'product_id'        => 'integer',
        'unit_id'           => 'integer',
        'attribute_id'      => 'integer',
        'quantity_ordered'  => 'decimal:4',
        'quantity_received' => 'decimal:4',
        'conversion_factor' => 'decimal:10',
        'base_quantity'     => 'decimal:6',
        'no_of_pieces'      => 'integer',
        'unit_price'        => 'decimal:4',
        'discount'          => 'decimal:4',
        'tax'               => 'decimal:4',
        'line_total'        => 'decimal:4',
        'expiry_date'       => 'date',
    ];

    public function grn(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNote::class, 'grn_id');
    }

    public function poItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'po_item_id');
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

    public function batchAssignments(): HasMany
    {
        return $this->hasMany(GrnItemBatch::class, 'grn_item_id');
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(GrnItemPiece::class, 'grn_item_id');
    }
}
