<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One debit or one credit against one ledger account.
 *
 * No SoftDeletes: a line has no life of its own. It is created with its
 * voucher and removed only by the cascade when a DRAFT voucher is deleted --
 * and a posted voucher can never be deleted at all.
 *
 * party_type + party_id is the subledger. It is what lets one shared Accounts
 * Payable account serve unlimited suppliers, so asking what we owe one
 * supplier is a WHERE clause instead of an account per supplier.
 *
 * source_type + source_id names the document this line clears, which is what
 * lets Aged Payables age per GRN rather than only per supplier. It never
 * references an allocation row id: the payment services delete and re-insert
 * their child rows on every draft save, so those ids are not stable.
 */
class JournalLine extends Model
{
    protected $table = 'fin_journal_lines';

    protected $fillable = [
        'journal_entry_id',
        'ledger_account_id',
        'line_no',
        'debit',
        'credit',
        'description',
        'company_id',
        'location_id',
        'party_type',
        'party_id',
        'source_type',
        'source_id',
    ];

    protected $casts = [
        'journal_entry_id'  => 'integer',
        'ledger_account_id' => 'integer',
        'line_no'           => 'integer',
        'debit'             => 'decimal:4',
        'credit'            => 'decimal:4',
        'company_id'        => 'integer',
        'location_id'       => 'integer',
        'party_id'          => 'integer',
        'source_id'         => 'integer',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /**
     * Restrict to lines that count towards a balance. Every report starts here.
     *
     * Deliberately named for what it means rather than for a status, because
     * the obvious reading is wrong: a REVERSED voucher still counts. Reversing
     * adds a mirror-image journal instead of retracting the original, so the
     * pair nets to zero only when both are included. Filtering to Posted alone
     * would leave the reversal standing by itself and flip the sign of the very
     * balance it was meant to cancel.
     *
     * Only drafts are excluded -- they are not part of the books yet.
     */
    public function scopeInLedger(Builder $query): Builder
    {
        return $query->whereHas('entry', fn (Builder $q) => $q->inLedger());
    }

    /** All lines for one party -- the subledger query. */
    public function scopeForParty(Builder $query, string $partyType, int $partyId): Builder
    {
        return $query->where('party_type', $partyType)->where('party_id', $partyId);
    }

    /** Signed value, positive for a debit. */
    public function signedAmount(): float
    {
        return round((float) $this->debit - (float) $this->credit, 4);
    }
}
