<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Enums\StockReconciliationStatus;

class StockReconciliation extends Model
{
    use SoftDeletes;

    protected $table = 'inv_stock_reconciliations';

    protected $fillable = [
        'reconciliation_no',
        'product_id',
        'attribute_id',
        'store_id',
        'location_id',
        'batch_id',
        'unit_id',
        'system_qty_base',
        'counted_qty_base',
        'variance_qty_base',
        'source_type',
        'source_id',
        'also_adjust_po',
        'reason',
        'remarks',
        'status',
        'created_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'rejection_reason',
    ];

    protected $casts = [
        'product_id'         => 'integer',
        'attribute_id'       => 'integer',
        'store_id'           => 'integer',
        'location_id'        => 'integer',
        'batch_id'           => 'integer',
        'unit_id'            => 'integer',
        'system_qty_base'    => 'decimal:6',
        'counted_qty_base'   => 'decimal:6',
        'variance_qty_base'  => 'decimal:6',
        'source_id'          => 'integer',
        'also_adjust_po'     => 'boolean',
        'created_by'         => 'integer',
        'submitted_at'       => 'datetime',
        'approved_by'        => 'integer',
        'approved_at'        => 'datetime',
        'status'             => StockReconciliationStatus::class,
    ];

    public function pieces(): HasMany
    {
        return $this->hasMany(StockReconciliationPiece::class, 'reconciliation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
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

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'unit_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** The GRN this correction relates to, when source_type = 'grn'. Null otherwise. */
    public function sourceGrn(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNote::class, 'source_id');
    }
}
