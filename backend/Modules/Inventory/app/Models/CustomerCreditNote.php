<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Enums\CreditNoteStatus;
use Modules\Inventory\Enums\CustomerCreditNoteType;

class CustomerCreditNote extends Model
{
    protected $table = 'inv_customer_credit_notes';

    protected $fillable = [
        'credit_note_no',
        'customer_id',
        'credit_type',
        'amount',
        'remaining_balance',
        'remark',
        'status',
        'source_receipt_id',
        'created_by',
    ];

    protected $casts = [
        'customer_id'        => 'integer',
        'amount'             => 'decimal:4',
        'remaining_balance'  => 'decimal:4',
        'source_receipt_id'  => 'integer',
        'created_by'         => 'integer',
        'credit_type'        => CustomerCreditNoteType::class,
        'status'             => CreditNoteStatus::class,
    ];

    public function setoffs(): HasMany
    {
        return $this->hasMany(CustomerReceiptSetoff::class, 'credit_note_id');
    }
}
