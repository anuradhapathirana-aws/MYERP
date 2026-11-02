<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One expense line of a bill. Written only by BillService. */
class BillLine extends Model
{
    protected $table = 'fin_bill_lines';

    protected $fillable = [
        'line_no',
        'ledger_account_id',
        'description',
        'amount',
    ];

    protected $casts = [
        'bill_id'           => 'integer',
        'line_no'           => 'integer',
        'ledger_account_id' => 'integer',
        'amount'            => 'decimal:2',
        'tax_amount'        => 'decimal:2',
        'tax_code_id'       => 'integer',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'bill_id');
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
