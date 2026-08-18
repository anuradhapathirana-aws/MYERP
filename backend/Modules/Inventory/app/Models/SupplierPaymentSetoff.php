<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Enums\SetoffType;

class SupplierPaymentSetoff extends Model
{
    protected $table = 'inv_supplier_payment_setoffs';

    protected $fillable = [
        'payment_id',
        'setoff_type',
        'credit_note_id',
        'amount',
        'remark',
    ];

    protected $casts = [
        'payment_id'      => 'integer',
        'credit_note_id'  => 'integer',
        'amount'          => 'decimal:4',
        'setoff_type'     => SetoffType::class,
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'payment_id');
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(SupplierCreditNote::class, 'credit_note_id');
    }
}
