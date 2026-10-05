<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Enums\CustomerReturnStatus;

/**
 * Customer (sales) return — goods coming back against one issued/paid invoice.
 * Confirming it posts stock IN (reversing the invoice's delivery order) and credits
 * the customer: first against the invoice's outstanding, any excess as an open
 * sales_return credit note.
 */
class CustomerReturn extends Model
{
    use SoftDeletes;

    protected $table = 'inv_customer_returns';

    protected $fillable = [
        'return_no',
        'return_date',
        'customer_id',
        'invoice_id',
        'do_id',
        'store_id',
        'location_id',
        'status',
        'total_amount',
        'applied_to_invoice',
        'credit_note_id',
        'remarks',
        'created_by',
        'confirmed_by',
        'confirmed_at',
    ];

    protected $casts = [
        'return_date'        => 'date',
        'confirmed_at'       => 'datetime',
        'customer_id'        => 'integer',
        'invoice_id'         => 'integer',
        'do_id'              => 'integer',
        'store_id'           => 'integer',
        'location_id'        => 'integer',
        'credit_note_id'     => 'integer',
        'created_by'         => 'integer',
        'confirmed_by'       => 'integer',
        'total_amount'       => 'decimal:4',
        'applied_to_invoice' => 'decimal:4',
        'status'             => CustomerReturnStatus::class,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CustomerReturnItem::class, 'return_id');
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(CustomerReturnPiece::class, 'return_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerMaster::class, 'customer_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class, 'do_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CustomerCreditNote::class, 'credit_note_id');
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
