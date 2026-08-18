<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReceiptAllocation extends Model
{
    protected $table = 'inv_customer_receipt_allocations';

    protected $fillable = [
        'receipt_id',
        'reference_type',
        'reference_id',
        'invoice_date',
        'so_no',
        'do_no',
        'invoice_amount',
        'due_date',
        'outstanding_before',
        'discount',
        'receipt_amount',
        'line_remark',
    ];

    protected $casts = [
        'invoice_date'        => 'date',
        'due_date'            => 'date',
        'invoice_amount'      => 'decimal:4',
        'outstanding_before'  => 'decimal:4',
        'discount'            => 'decimal:4',
        'receipt_amount'      => 'decimal:4',
        'reference_id'        => 'integer',
        'receipt_id'          => 'integer',
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'receipt_id');
    }
}
