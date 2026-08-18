<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Enums\PurchaseOrderStatus;

class PurchaseOrder extends Model
{
    use SoftDeletes;

    protected $table = 'inv_purchase_orders';

    protected $fillable = [
        'po_no',
        'reference_no',
        'pr_id',
        'supplier_id',
        'store_id',
        'location_id',
        'location',
        'order_date',
        'expected_delivery_date',
        'payment_terms',
        'contact_person_name',
        'contact_person_phone',
        'is_consignment',
        'billing_address',
        'shipping_address',
        'status',
        'subtotal',
        'grand_total',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'order_date'             => 'date',
        'expected_delivery_date' => 'date',
        'is_consignment'         => 'boolean',
        'subtotal'               => 'decimal:4',
        'grand_total'            => 'decimal:4',
        'supplier_id'            => 'integer',
        'store_id'               => 'integer',
        'location_id'            => 'integer',
        'pr_id'                  => 'integer',
        'created_by'             => 'integer',
        'status'                 => PurchaseOrderStatus::class,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'po_id');
    }

    public function goodsReceivedNotes(): HasMany
    {
        return $this->hasMany(GoodsReceivedNote::class, 'po_id');
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'pr_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(SupplierMaster::class, 'supplier_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
