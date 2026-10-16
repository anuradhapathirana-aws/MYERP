<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Enums\ProformaInvoiceStatus;

/**
 * Proforma invoice — a priced, non-binding offer raised from a confirmed sales
 * order before delivery (for advance payment / customer approval). It never
 * touches stock, receivables or the ledger. Delivery-order invoices of the SO
 * auto-link to it and take its prices; their invoiced quantity drives the
 * PartiallyInvoiced / Converted status.
 */
class ProformaInvoice extends Model
{
    use SoftDeletes;

    public const SOURCE_SALES_ORDER = 'sales_order'; // legacy: raised from a confirmed SO
    public const SOURCE_DIRECT      = 'direct';      // proforma-first: raised before any SO

    protected $table = 'inv_proforma_invoices';

    protected $fillable = [
        'proforma_no',
        'source_type',
        'source_id',
        'so_id',
        'customer_id',
        'company_id',
        'proforma_date',
        'valid_until',
        'payment_terms',
        'invoice_type',
        'status',
        'subtotal',
        'transport_charge',
        'grand_total',
        'delivery_address',
        'remarks',
        'created_by',
        'issued_at',
        'converted_at',
        'cancelled_at',
    ];

    protected $casts = [
        'proforma_date'    => 'date',
        'valid_until'      => 'date',
        'issued_at'        => 'datetime',
        'converted_at'     => 'datetime',
        'cancelled_at'     => 'datetime',
        'subtotal'         => 'decimal:4',
        'transport_charge' => 'decimal:4',
        'grand_total'      => 'decimal:4',
        'source_id'        => 'integer',
        'so_id'            => 'integer',
        'customer_id'      => 'integer',
        'company_id'       => 'integer',
        'created_by'       => 'integer',
        'status'           => ProformaInvoiceStatus::class,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ProformaInvoiceItem::class, 'proforma_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'proforma_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'so_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerMaster::class, 'customer_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Validity is derived, never stored — an offer past valid_until shows as expired. */
    public function isExpired(): bool
    {
        return $this->valid_until !== null
            && $this->valid_until->lt(now()->startOfDay())
            && in_array($this->status, [ProformaInvoiceStatus::Draft, ProformaInvoiceStatus::Issued], true);
    }
}
