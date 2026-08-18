<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Enums\DeliveryOrderStatus;
use Modules\Inventory\Enums\InvoiceStatus;

/**
 * Delivery Order — physical dispatch of a sales order (partial or full).
 * Confirming a DO posts the outbound stock ledger rows ('sales_delivery'),
 * decrements location stock and flips the shipped rolls to 'delivered'.
 * Confirmed DOs are immutable; reversal belongs to a future customer-return.
 */
class DeliveryOrder extends Model
{
    use SoftDeletes;

    protected $table = 'inv_delivery_orders';

    protected $fillable = [
        'do_no',
        'document_date',
        'so_id',
        'customer_id',
        'driver_id',
        'vehicle_id',
        'store_id',
        'location_id',
        'delivery_date',
        'delivery_mode',
        'delivery_vehicle',
        'responsible_person',
        'delivery_address',
        'status',
        'remarks',
        'created_by',
        'confirmed_at',
        'confirmed_by',
    ];

    protected $casts = [
        'document_date' => 'date',
        'delivery_date' => 'date',
        'confirmed_at'  => 'datetime',
        'so_id'         => 'integer',
        'customer_id'   => 'integer',
        'driver_id'     => 'integer',
        'vehicle_id'    => 'integer',
        'store_id'      => 'integer',
        'location_id'   => 'integer',
        'created_by'    => 'integer',
        'confirmed_by'  => 'integer',
        'status'        => DeliveryOrderStatus::class,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(DeliveryOrderItem::class, 'do_id');
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(DeliveryOrderPiece::class, 'do_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'so_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerMaster::class, 'customer_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(VehicleMaster::class, 'vehicle_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** The live (non-cancelled) invoice raised against this DO, if any. */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'do_id')
            ->where('status', '!=', InvoiceStatus::Cancelled->value);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
