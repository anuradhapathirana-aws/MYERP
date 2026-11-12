<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\Enums\ReconLineStatus;

/** One bank line of the ledger, as the statement shows it. */
class BankReconciliationLine extends Model
{
    public const DEPOSIT    = 'deposit';
    public const WITHDRAWAL = 'withdrawal';

    protected $table = 'fin_bank_reconciliation_lines';

    protected $fillable = [
        'bank_reconciliation_id',
        'journal_line_id',
        'direction',
        'status',
        'cleared_date',
        'journal_no',
        'journal_date',
        'instrument_type',
        'instrument_no',
        'instrument_date',
        'description',
        'amount',
        'return_journal_no',
    ];

    protected $casts = [
        'bank_reconciliation_id' => 'integer',
        'journal_line_id'        => 'integer',
        'status'                 => ReconLineStatus::class,
        'cleared_date'           => 'date',
        'journal_date'           => 'date',
        'instrument_date'        => 'date',
        'amount'                 => 'decimal:2',
    ];

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class, 'journal_line_id');
    }
}
