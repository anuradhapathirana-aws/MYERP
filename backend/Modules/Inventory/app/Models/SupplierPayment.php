<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Enums\SupplierPaymentStatus;

class SupplierPayment extends Model
{
    use SoftDeletes;

    protected $table = 'inv_supplier_payments';

    protected $fillable = [
        'payment_no',
        'payment_date',
        'transaction_date',
        'reference_no',
        'supplier_type',
        'supplier_id',
        'payment_remark',
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
        'payment_date'     => 'date',
        'transaction_date' => 'date',
        'confirmed_at'     => 'datetime',
        'is_advance'       => 'boolean',
        'advance_amount'   => 'decimal:4',
        'gross_amount'     => 'decimal:4',
        'discount_amount'  => 'decimal:4',
        'setoff_amount'    => 'decimal:4',
        'net_amount'       => 'decimal:4',
        'supplier_id'      => 'integer',
        'created_by'       => 'integer',
        'status'           => SupplierPaymentStatus::class,
    ];

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class, 'payment_id');
    }

    public function setoffs(): HasMany
    {
        return $this->hasMany(SupplierPaymentSetoff::class, 'payment_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(SupplierPaymentSettlement::class, 'payment_id');
    }
}
