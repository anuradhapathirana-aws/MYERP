<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Enums\SalesOrderStatus;

/**
 * Sales Order — commitment document only. Confirming a sales order does NOT
 * post stock transactions; allocated pieces are a reservation. The future
 * Delivery/Dispatch feature will post qty_out rows to inv_stock_transactions
 * and flip pieces from 'allocated' to out of stock.
 */
class SalesOrder extends Model
{
    use SoftDeletes;

    protected $table = 'inv_sales_orders';

    protected $fillable = [
        'so_no',
        'reference_no',
        'customer_id',
        'sales_person_id',
        'order_taken_by',
        'customer_type',
        'order_date',
        'expected_date',
        'transaction_date',
        'order_source',
        'delivery_address',
        'status',
        'subtotal',
        'transport_charge',
        'grand_total',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'order_date'       => 'date',
        'expected_date'    => 'date',
        'transaction_date' => 'date',
        'subtotal'         => 'decimal:4',
        'transport_charge' => 'decimal:4',
        'grand_total'      => 'decimal:4',
        'customer_id'      => 'integer',
        'sales_person_id'  => 'integer',
        'order_taken_by'   => 'integer',
        'created_by'       => 'integer',
        'status'           => SalesOrderStatus::class,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class, 'so_id');
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(SalesOrderPiece::class, 'so_id');
    }

    public function deliveryOrders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class, 'so_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'so_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerMaster::class, 'customer_id');
    }

    public function salesPerson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_person_id');
    }

    public function orderTakenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'order_taken_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
