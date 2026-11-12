<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Finance\Enums\BankReconciliationStatus;

/**
 * One bank account matched against one bank statement.
 *
 * $fillable is deliberately narrow, as on BankDeposit. recon_no, status, the
 * balances and every posted_* / voided_* / created_by column are set by
 * BankReconciliationService alone.
 */
class BankReconciliation extends Model
{
    use SoftDeletes;

    public const AUDIT_TYPE = 'bank_reconciliation';

    protected $table = 'fin_bank_reconciliations';

    protected $fillable = [
        'company_id',
        'bank_account_id',
        'date_from',
        'statement_date',
        // Typed only on an account's first reconciliation; after that the
        // service takes it from the last posted statement balance.
        'opening_balance',
        'statement_balance',
        'notes',
    ];

    protected $casts = [
        'company_id'          => 'integer',
        'bank_account_id'     => 'integer',
        'date_from'           => 'date',
        'statement_date'      => 'date',
        'opening_balance'     => 'decimal:2',
        'statement_balance'   => 'decimal:2',
        'cleared_deposits'    => 'decimal:2',
        'cleared_withdrawals' => 'decimal:2',
        'closing_balance'     => 'decimal:2',
        'difference'          => 'decimal:2',
        'book_balance'        => 'decimal:2',
        'status'              => BankReconciliationStatus::class,
        'posted_at'           => 'datetime',
        'voided_at'           => 'datetime',
        'posted_by'           => 'integer',
        'voided_by'           => 'integer',
        'created_by'          => 'integer',
        'updated_by'          => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(BankReconciliationLine::class, 'bank_reconciliation_id')
            ->orderBy('journal_date')
            ->orderBy('id');
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

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
