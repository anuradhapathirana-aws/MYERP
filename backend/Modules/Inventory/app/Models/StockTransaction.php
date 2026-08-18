<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransaction extends Model
{
    protected $table = 'inv_stock_transactions';

    protected $fillable = [
        'transaction_date',
        'reference_type',
        'reference_id',
        'product_id',
        'attribute_id',
        'store_id',
        'location_id',
        'batch_no',
        'batch_id',
        'expiry_date',
        'qty_in',
        'qty_out',
        'unit_id',
        'entered_unit_id',
        'entered_qty',
        'unit_price',
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'datetime',
        'expiry_date'      => 'date',
        // Ledger movements are in the product's base UOM — decimal(20,6).
        'qty_in'           => 'decimal:6',
        'qty_out'          => 'decimal:6',
        // What the user actually keyed, in the unit they keyed it in (10 Roll, not 500 Kg).
        'entered_qty'      => 'decimal:6',
        'unit_price'       => 'decimal:8',
        'reference_id'     => 'integer',
        'product_id'       => 'integer',
        'attribute_id'     => 'integer',
        'store_id'         => 'integer',
        'location_id'      => 'integer',
        'batch_id'         => 'integer',
        'unit_id'          => 'integer',
        'entered_unit_id'  => 'integer',
        'created_by'       => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    /** Colour (or other variant attribute) this movement was of. */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }

    /** The stocking UOM qty_in/qty_out are denominated in. */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'unit_id');
    }

    /** The UOM the movement was transacted in — what entered_qty is denominated in. */
    public function enteredUnit(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'entered_unit_id');
    }
}
