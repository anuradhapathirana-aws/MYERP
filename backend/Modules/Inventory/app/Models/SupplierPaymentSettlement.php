<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPaymentSettlement extends Model
{
    protected $table = 'inv_supplier_payment_settlements';

    protected $fillable = [
        'payment_id',
        'payment_mode_id',
        'payment_mode_code',
        'payment_mode_name',
        'amount',
        'bank_name',
        'bank_account_no',
        'reference_no',
        'instrument_date',
        'is_thirdparty',
        'remark',
    ];

    protected $casts = [
        'payment_id'       => 'integer',
        'payment_mode_id'  => 'integer',
        'amount'           => 'decimal:4',
        'instrument_date'  => 'date',
        'is_thirdparty'    => 'boolean',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'payment_id');
    }
}
