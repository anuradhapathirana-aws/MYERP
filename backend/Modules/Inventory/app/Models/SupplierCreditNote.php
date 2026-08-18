<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Enums\CreditNoteStatus;
use Modules\Inventory\Enums\CreditNoteType;

class SupplierCreditNote extends Model
{
    protected $table = 'inv_supplier_credit_notes';

    protected $fillable = [
        'credit_note_no',
        'supplier_id',
        'credit_type',
        'amount',
        'remaining_balance',
        'remark',
        'status',
        'source_payment_id',
        'created_by',
    ];

    protected $casts = [
        'supplier_id'        => 'integer',
        'amount'             => 'decimal:4',
        'remaining_balance'  => 'decimal:4',
        'source_payment_id'  => 'integer',
        'created_by'         => 'integer',
        'credit_type'        => CreditNoteType::class,
        'status'             => CreditNoteStatus::class,
    ];

    public function setoffs(): HasMany
    {
        return $this->hasMany(SupplierPaymentSetoff::class, 'credit_note_id');
    }
}
