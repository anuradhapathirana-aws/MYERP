<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One bill settled by a payment, and how much of it. */
class BillPaymentAllocation extends Model
{
    protected $table = 'fin_bill_payment_allocations';

    protected $fillable = [
        'bill_payment_id',
        'bill_id',
        'line_no',
        'amount',
        'balance_before',
    ];

    protected $casts = [
        'bill_payment_id' => 'integer',
        'bill_id'         => 'integer',
        'line_no'         => 'integer',
        'amount'          => 'decimal:2',
        'balance_before'  => 'decimal:2',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(BillPayment::class, 'bill_payment_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'bill_id');
    }
}
