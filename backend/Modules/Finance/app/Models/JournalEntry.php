<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use App\Models\Company;
use App\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Finance\Enums\JournalStatus;
use Modules\Finance\Enums\JournalType;

/**
 * A general ledger voucher.
 *
 * Together with JournalLine this is the entire accounting record. Every
 * document in the system -- GRN, invoice, payment, receipt, petty cash,
 * payroll later -- lands here, and every financial report is a query over
 * these two tables. Nothing stores a balance.
 *
 * journal_no is excluded from fillable deliberately, the same way code is on
 * the chart-of-accounts models: it is allocated under lockForUpdate() by the
 * posting service and must never arrive from user input.
 */
class JournalEntry extends Model
{
    use SoftDeletes;

    protected $table = 'fin_journal_entries';

    protected $fillable = [
        'journal_date',
        'document_date',
        'currency',
        'exchange_rate',
        'journal_type',
        'source_type',
        'source_id',
        'company_id',
        'location_id',
        'narration',
        'status',
        'total_debit',
        'total_credit',
        'reverses_journal_id',
        'reversed_by_journal_id',
        'fiscal_year_id',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    protected $casts = [
        'journal_date'           => 'date',
        'document_date'          => 'date',
        'posted_at'              => 'datetime',
        'exchange_rate'          => 'decimal:6',
        'total_debit'            => 'decimal:4',
        'total_credit'           => 'decimal:4',
        'journal_type'           => JournalType::class,
        'status'                 => JournalStatus::class,
        'source_id'              => 'integer',
        'company_id'             => 'integer',
        'location_id'            => 'integer',
        'reverses_journal_id'    => 'integer',
        'reversed_by_journal_id' => 'integer',
        'fiscal_year_id'         => 'integer',
        'created_by'             => 'integer',
        'posted_by'              => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id')->orderBy('line_no');
    }

    /** The journal this one reverses, if it is a reversal. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_journal_id');
    }

    /** The reversal that cancelled this journal, if one exists. */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_journal_id');
    }

    /** Soft links into core -- inv_companies has no SoftDeletes, hence no FK. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * Journals in their live posted state. For asking whether a document has
     * a current posting -- NOT for reports. See scopeInLedger().
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', JournalStatus::Posted);
    }

    /**
     * Everything that counts towards a balance. THIS is what every report uses.
     *
     * Reversed journals are included, and that is not a loose end. Reversing
     * does not retract the original: it adds a mirror-image journal, and the
     * two net to zero only if BOTH are counted. Excluding the original would
     * leave the reversal standing alone and invert the balance it was meant to
     * cancel -- the Accounts Payable in a voided payment would read as a
     * negative liability.
     *
     * So only DRAFT is excluded, because a draft is not yet part of the books.
     */
    public function scopeInLedger(Builder $query): Builder
    {
        return $query->whereIn('status', [JournalStatus::Posted, JournalStatus::Reversed]);
    }

    /** The journal written for one specific document. */
    public function scopeForSource(Builder $query, string $sourceType, int $sourceId): Builder
    {
        return $query->where('source_type', $sourceType)->where('source_id', $sourceId);
    }

    /**
     * Whether the stored totals agree with each other.
     *
     * Compared at two decimals: the source amounts are stored at four and
     * every decision in the payment services rounds to cents, so an exact
     * comparison would fail on float noise rather than on a real imbalance.
     */
    public function isBalanced(): bool
    {
        return abs(round((float) $this->total_debit - (float) $this->total_credit, 2)) <= 0.01;
    }
}
