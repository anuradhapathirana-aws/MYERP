<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Enums\CustomerSetoffType;

class CustomerReceiptSetoff extends Model
{
    protected $table = 'inv_customer_receipt_setoffs';

    protected $fillable = [
        'receipt_id',
        'setoff_type',
        'credit_note_id',
        'amount',
        'remark',
    ];

    protected $casts = [
        'receipt_id'      => 'integer',
        'credit_note_id'  => 'integer',
        'amount'          => 'decimal:4',
        'setoff_type'     => CustomerSetoffType::class,
    ];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'receipt_id');
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CustomerCreditNote::class, 'credit_note_id');
    }
}
