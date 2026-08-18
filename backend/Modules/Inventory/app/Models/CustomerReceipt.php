<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Enums\CustomerReceiptStatus;

class CustomerReceipt extends Model
{
    use SoftDeletes;

    protected $table = 'inv_customer_receipts';

    protected $fillable = [
        'receipt_no',
        'receipt_date',
        'transaction_date',
        'reference_no',
        'customer_id',
        'receipt_remark',
        'is_advance',
        'advance_amount',
        'gross_amount',
        'discount_amount',
        'setoff_amount',
        'net_amount',
        'status',
        'created_by',
        'confirmed_at',
    ];

    protected $casts = [
        'receipt_date'     => 'date',
        'transaction_date' => 'date',
        'confirmed_at'     => 'datetime',
        'is_advance'       => 'boolean',
        'advance_amount'   => 'decimal:4',
        'gross_amount'     => 'decimal:4',
        'discount_amount'  => 'decimal:4',
        'setoff_amount'    => 'decimal:4',
        'net_amount'       => 'decimal:4',
        'customer_id'      => 'integer',
        'created_by'       => 'integer',
        'status'           => CustomerReceiptStatus::class,
    ];

    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerReceiptAllocation::class, 'receipt_id');
    }

    public function setoffs(): HasMany
    {
        return $this->hasMany(CustomerReceiptSetoff::class, 'receipt_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(CustomerReceiptSettlement::class, 'receipt_id');
    }
}
