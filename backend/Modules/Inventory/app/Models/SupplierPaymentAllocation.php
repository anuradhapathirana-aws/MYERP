<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPaymentAllocation extends Model
{
    protected $table = 'inv_supplier_payment_allocations';

    protected $fillable = [
        'payment_id',
        'reference_type',
        'reference_id',
        'grn_date',
        'po_no',
        'reference_no',
        'grn_amount',
        'due_date',
        'outstanding_before',
        'discount',
        'payment_amount',
        'line_remark',
    ];

    protected $casts = [
        'grn_date'            => 'date',
        'due_date'            => 'date',
        'grn_amount'          => 'decimal:4',
        'outstanding_before'  => 'decimal:4',
        'discount'            => 'decimal:4',
        'payment_amount'      => 'decimal:4',
        'reference_id'        => 'integer',
        'payment_id'          => 'integer',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'payment_id');
    }
}
