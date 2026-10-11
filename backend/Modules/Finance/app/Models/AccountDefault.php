<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\Enums\AccountRole;

/**
 * Which ledger account plays which role in a posting.
 *
 * Setup data, not a transaction. One row per role, optionally overridden per
 * company. No SoftDeletes: a mapping is either current or replaced, and the
 * journals that used an older mapping already store the resolved account id,
 * so nothing historical depends on this row surviving.
 */
class AccountDefault extends Model
{
    protected $table = 'fin_account_defaults';

    protected $fillable = [
        'rule_key',
        'ledger_account_id',
        'company_id',
        'description',
    ];

    protected $casts = [
        'rule_key'          => AccountRole::class,
        'ledger_account_id' => 'integer',
        'company_id'        => 'integer',
    ];

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
