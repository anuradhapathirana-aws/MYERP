<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Enums\BatchStatus;

class Batch extends Model
{
    use SoftDeletes;

    protected $table = 'inv_batches';

    protected $fillable = [
        'batch_no',
        'product_id',
        'supplier_id',
        'supplier_batch_no',
        'mfg_date',
        'expiry_date',
        'received_date',
        'initial_qty',
        'current_qty',
        'unit_cost',
        'status',
        'country_of_origin',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'mfg_date'      => 'date',
        'expiry_date'   => 'date',
        'received_date' => 'date',
        // Batch quantities are stock balances in the product's base UOM — decimal(20,6).
        'initial_qty'   => 'decimal:6',
        'current_qty'   => 'decimal:6',
        'unit_cost'     => 'decimal:8',
        'status'        => BatchStatus::class,
        'product_id'    => 'integer',
        'supplier_id'   => 'integer',
        'created_by'    => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(SupplierMaster::class, 'supplier_id');
    }

    public function grnItemBatches(): HasMany
    {
        return $this->hasMany(GrnItemBatch::class, 'batch_id');
    }

    public function stockTransactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class, 'batch_id');
    }
}
