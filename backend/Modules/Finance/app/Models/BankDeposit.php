<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Finance\Enums\BankDepositStatus;

/**
 * Cash and received cheques paid into one bank account on one slip.
 *
 * $fillable is deliberately narrow, as on BillPayment. deposit_no, status, the
 * totals, journal numbers and every confirmed_* / voided_* / created_by column
 * are set by BankDepositService alone.
 */
class BankDeposit extends Model
{
    use SoftDeletes;

    public const AUDIT_TYPE = 'bank_deposit';

    protected $table = 'fin_bank_deposits';

    protected $fillable = [
        'company_id',
        'deposit_date',
        'bank_account_id',
        'slip_no',
        'description',
    ];

    protected $casts = [
        'company_id'      => 'integer',
        'bank_account_id' => 'integer',
        'deposit_date'    => 'date',
        'reversal_date'   => 'date',
        'exchange_rate'   => 'decimal:6',
        'cash_total'      => 'decimal:2',
        'cheque_total'    => 'decimal:2',
        'total_amount'    => 'decimal:2',
        'status'          => BankDepositStatus::class,
        'confirmed_at'    => 'datetime',
        'voided_at'       => 'datetime',
        'confirmed_by'    => 'integer',
        'voided_by'       => 'integer',
        'created_by'      => 'integer',
        'updated_by'      => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(BankDepositLine::class, 'bank_deposit_id')->orderBy('line_no');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'bank_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'attachable_id')
            ->where('attachable_type', self::AUDIT_TYPE)
            ->orderBy('id');
    }
}
