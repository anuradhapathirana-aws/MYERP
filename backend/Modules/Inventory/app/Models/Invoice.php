<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Enums\InvoiceStatus;
use Modules\Inventory\Enums\PaymentMode;

/**
 * Invoice — billing document. Raised either against one confirmed delivery
 * order (do_id set) or directly against a sales order (do_id null, advance
 * billing). An SO's billing mode is fixed by its first non-cancelled invoice.
 */
class Invoice extends Model
{
    use SoftDeletes;

    protected $table = 'inv_invoices';

    protected $fillable = [
        'invoice_no',
        'so_id',
        'do_id',
        'customer_id',
        'company_id',
        'invoice_date',
        'due_date',
        'status',
        'invoice_type',
        'subtotal',
        'transport_charge',
        'grand_total',
        'delivery_address',
        'remarks',
        'mode_of_payment',
        'created_by',
        'issued_at',
        'paid_at',
    ];

    protected $casts = [
        'invoice_date'     => 'date',
        'due_date'         => 'date',
        'issued_at'        => 'datetime',
        'paid_at'          => 'datetime',
        'subtotal'         => 'decimal:4',
        'transport_charge' => 'decimal:4',
        'grand_total'      => 'decimal:4',
        'so_id'            => 'integer',
        'do_id'            => 'integer',
        'customer_id'      => 'integer',
        'company_id'       => 'integer',
        'created_by'       => 'integer',
        'status'           => InvoiceStatus::class,
        'mode_of_payment'  => PaymentMode::class,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'so_id');
    }

    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class, 'do_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerMaster::class, 'customer_id');
    }

    /** The company that issued this invoice — the supplier on the printed tax invoice. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
