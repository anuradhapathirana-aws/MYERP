<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Finance\Enums\AccountType;

/**
 * Level 2 of the chart of accounts.
 *
 * The account type is NOT stored here — it is derived from the parent
 * category. Duplicating it would create a second source of truth that can
 * drift, and the code already carries it in its first digit.
 */
class ControlAccount extends Model
{
    use SoftDeletes;

    protected $table = 'fin_control_accounts';

    /** `code` is allocated by the generator and never mass-assigned. */
    protected $fillable = [
        'account_category_id',
        'control_account_name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'account_category_id' => 'integer',
        'is_active'           => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(AccountCategory::class, 'account_category_id');
    }

    public function ledgerAccounts(): HasMany
    {
        return $this->hasMany(LedgerAccount::class, 'control_account_id');
    }

    /** Derived from the parent category — the single source of truth. */
    public function accountType(): ?AccountType
    {
        return $this->category?->account_type;
    }
}
