<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Enums\PurchaseRequestStatus;

class PurchaseRequest extends Model
{
    use SoftDeletes;

    protected $table = 'inv_purchase_requests';

    protected $fillable = [
        'pr_no',
        'reference_no',
        'request_date',
        'required_date',
        'purpose',
        'source_location_id',
        'source_store_id',
        'target_location_id',
        'target_store_id',
        'customer_id',
        'transport_mode',
        'remarks',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'rejection_reason',
    ];

    protected $casts = [
        'request_date'       => 'date',
        'required_date'      => 'date',
        'approved_at'        => 'datetime',
        'source_location_id' => 'integer',
        'source_store_id'    => 'integer',
        'target_location_id' => 'integer',
        'target_store_id'    => 'integer',
        'customer_id'        => 'integer',
        'requested_by'       => 'integer',
        'approved_by'        => 'integer',
        'status'             => PurchaseRequestStatus::class,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class, 'pr_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'pr_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerMaster::class, 'customer_id');
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    public function sourceStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'source_store_id');
    }

    public function targetLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'target_location_id');
    }

    public function targetStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'target_store_id');
    }
}
