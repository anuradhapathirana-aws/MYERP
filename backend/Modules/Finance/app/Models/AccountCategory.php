<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Finance\Enums\AccountType;

/**
 * Level 1 of the chart of accounts.
 *
 * `code` is absent from $fillable on purpose — it is allocated by
 * AccountCodeGenerator inside a locked transaction and is immutable
 * thereafter. Keeping it unfillable means a stray mass-assignment can never
 * renumber an account that journal entries already reference.
 */
class AccountCategory extends Model
{
    use SoftDeletes;

    protected $table = 'fin_account_categories';

    protected $fillable = [
        'account_type',
        'category_name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'account_type' => AccountType::class,
        'is_active'    => 'boolean',
    ];

    public function controlAccounts(): HasMany
    {
        return $this->hasMany(ControlAccount::class, 'account_category_id');
    }

    /** Every ledger account in this category, reached through its control accounts. */
    public function ledgerAccounts(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(
            LedgerAccount::class,
            ControlAccount::class,
            'account_category_id',
            'control_account_id',
        );
    }
}
