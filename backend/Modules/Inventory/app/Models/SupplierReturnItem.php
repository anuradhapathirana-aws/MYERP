<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Enums\ReturnReason;

class SupplierReturnItem extends Model
{
    protected $table = 'inv_supplier_return_items';

    protected $fillable = [
        'return_id',
        'grn_item_id',
        'product_id',
        'attribute_id',
        'variant_id',
        'unit_id',
        'quantity',
        'base_quantity',
        'unit_price',
        'discount',
        'tax',
        'line_total',
        'store_id',
        'location_id',
        'reason',
        'remarks',
    ];

    protected $casts = [
        'return_id'     => 'integer',
        'grn_item_id'   => 'integer',
        'product_id'    => 'integer',
        'attribute_id'  => 'integer',
        'variant_id'    => 'integer',
        'unit_id'       => 'integer',
        'store_id'      => 'integer',
        'location_id'   => 'integer',
        'quantity'      => 'decimal:4',
        'base_quantity' => 'decimal:6',
        'unit_price'    => 'decimal:4',
        'discount'      => 'decimal:4',
        'tax'           => 'decimal:4',
        'line_total'    => 'decimal:4',
        'reason'        => ReturnReason::class,
    ];

    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(SupplierReturn::class, 'return_id');
    }

    public function grnItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNoteItem::class, 'grn_item_id');
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(SupplierReturnPiece::class, 'return_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }

    /** The product variant (Red / Floral / M) — null for roll products and products without variants. */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
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
