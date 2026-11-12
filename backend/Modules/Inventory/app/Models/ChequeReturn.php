<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cheque the bank returned unpaid. Written once by ChequeReturnService when
 * a Bank Reconciliation posts it as Returned; never edited or deleted.
 */
class ChequeReturn extends Model
{
    public const DOCUMENT_CUSTOMER_RECEIPT = 'customer_receipt';
    public const DOCUMENT_SUPPLIER_PAYMENT = 'supplier_payment';

    protected $table = 'inv_cheque_returns';

    protected $fillable = [
        'settlement_type',
        'settlement_id',
        'document_type',
        'document_id',
        'document_no',
        'party_type',
        'party_id',
        'cheque_no',
        'cheque_date',
        'amount',
        'return_date',
        'reference',
        'bank_account_id',
        'journal_no',
        'created_by',
    ];

    protected $casts = [
        'settlement_id'   => 'integer',
        'document_id'     => 'integer',
        'party_id'        => 'integer',
        'cheque_date'     => 'date',
        'return_date'     => 'date',
        'amount'          => 'decimal:4',
        'bank_account_id' => 'integer',
        'created_by'      => 'integer',
    ];

    public function allocations(): HasMany
    {
        return $this->hasMany(ChequeReturnAllocation::class, 'cheque_return_id');
    }
}
