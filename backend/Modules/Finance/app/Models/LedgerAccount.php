<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use App\Models\Bank;
use App\Models\BankBranch;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Finance\Enums\AccountType;
use Modules\Finance\Enums\CashBookType;

/**
 * Level 3 of the chart of accounts — the only postable level.
 *
 * The bank(), bankBranch() and company() relations point at CORE models
 * (App\Models), never into another module. Core is always installed, so
 * Finance can rely on it with Inventory switched off entirely.
 *
 * bank_id and bank_branch_id carry real foreign keys; company_id does not.
 * The migration explains why — it comes down to inv_companies having no
 * SoftDeletes, not to any module boundary.
 */
class LedgerAccount extends Model
{
    use SoftDeletes;

    protected $table = 'fin_ledger_accounts';

    /** `code` is allocated by the generator and never mass-assigned. */
    protected $fillable = [
        'control_account_id',
        'ledger_account_name',
        'description',
        'is_active',
        'cash_book_type',
        'company_id',
        'allows_cash',
        'allows_cheque',
        'bank_id',
        'bank_branch_id',
        'bank_account_no',
    ];

    protected $casts = [
        'control_account_id' => 'integer',
        'is_active'          => 'boolean',
        'cash_book_type'     => CashBookType::class,
        'company_id'         => 'integer',
        'allows_cash'        => 'boolean',
        'allows_cheque'      => 'boolean',
        'bank_id'            => 'integer',
        'bank_branch_id'     => 'integer',
    ];

    public function controlAccount(): BelongsTo
    {
        return $this->belongsTo(ControlAccount::class, 'control_account_id');
    }

    /**
     * The legal entity that owns this cash/bank account.
     *
     * NULL on ordinary accounts, which are shared by every company — the chart
     * of accounts is group-wide and only the money-bearing accounts belong to
     * one entity. See the migration for why.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    public function bankBranch(): BelongsTo
    {
        return $this->belongsTo(BankBranch::class, 'bank_branch_id');
    }

    /** Derived two levels up — the single source of truth for the type. */
    public function accountType(): ?AccountType
    {
        return $this->controlAccount?->category?->account_type;
    }

    /**
     * Accounts money can be paid out of or received into.
     *
     * Payment forms use this instead of listing the whole chart: a Normal
     * Payment offers cash_book + bank, a Petty Cash Payment offers
     * petty_cash_book. Kept as a scope so the filter is defined once.
     *
     * @param  list<string>|null  $types      Restrict to specific CashBookType values.
     * @param  int|null           $companyId  Restrict to one legal entity's accounts.
     */
    public function scopeFundSources(Builder $query, ?array $types = null, ?int $companyId = null): Builder
    {
        return $query
            ->whereNotNull('cash_book_type')
            ->when($types !== null, fn (Builder $q) => $q->whereIn('cash_book_type', $types))
            // A payment made by one company may only draw on that company's
            // own cash and bank accounts.
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId));
    }
}
