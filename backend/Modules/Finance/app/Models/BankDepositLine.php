<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The cash, or one cheque, carried by a deposit. */
class BankDepositLine extends Model
{
    public const TYPE_CASH   = 'cash';
    public const TYPE_CHEQUE = 'cheque';

    protected $table = 'fin_bank_deposit_lines';

    protected $fillable = [
        'bank_deposit_id',
        'line_no',
        'line_type',
        'from_account_id',
        'source_type',
        'source_id',
        'receipt_no',
        'receipt_date',
        'customer_name',
        'cheque_no',
        'cheque_date',
        'drawee_bank',
        'amount',
    ];

    protected $casts = [
        'bank_deposit_id' => 'integer',
        'line_no'         => 'integer',
        'from_account_id' => 'integer',
        'source_id'       => 'integer',
        'receipt_date'    => 'date',
        'cheque_date'     => 'date',
        'amount'          => 'decimal:2',
    ];

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(BankDeposit::class, 'bank_deposit_id');
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'from_account_id');
    }

    public function isCheque(): bool
    {
        return $this->line_type === self::TYPE_CHEQUE;
    }
}
