<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Enums\CustomerReturnStatus;
use Modules\Inventory\Enums\InvoiceReturnStatus;
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
        'proforma_id',
        'customer_id',
        'company_id',
        'invoice_date',
        'due_date',
        'status',
        'return_status',
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

    /** Mirrors the column default, so a freshly created invoice reads 'none' without a refresh. */
    protected $attributes = [
        'return_status' => 'none',
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
        'proforma_id'      => 'integer',
        'customer_id'      => 'integer',
        'company_id'       => 'integer',
        'created_by'       => 'integer',
        'status'           => InvoiceStatus::class,
        'return_status'    => InvoiceReturnStatus::class,
        // Modules\Inventory\Enums\PaymentMode (a backed enum cast), NOT the
        // relocated App\Models\PaymentMode master. Do not "fix" this import.
        'mode_of_payment'  => PaymentMode::class,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id');
    }

    /** Confirmed customer returns raised against this invoice — drafts never touch it. */
    public function customerReturns(): HasMany
    {
        return $this->hasMany(CustomerReturn::class, 'invoice_id')
            ->where('status', CustomerReturnStatus::Confirmed->value)
            ->orderBy('id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'so_id');
    }

    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class, 'do_id');
    }

    /** The SO's proforma this DO invoice was auto-linked to (soft link, nullable). */
    public function proforma(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class, 'proforma_id');
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
