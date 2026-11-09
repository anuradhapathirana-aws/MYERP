<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tax on a bill line (VAT 18% on 40,000 = 7,200, claimable) — kept for the
 * VAT return. Same shape as Inventory's GRN line taxes. Written only by
 * BillService.
 */
class BillLineTax extends Model
{
    protected $table = 'fin_bill_line_taxes';

    protected $fillable = [
        'bill_line_id',
        'tax_code_ref',
        'rate',
        'base',
        'amount',
        'is_recoverable',
    ];

    protected $casts = [
        'bill_line_id'   => 'integer',
        'rate'           => 'decimal:4',
        'base'           => 'decimal:2',
        'amount'         => 'decimal:2',
        'is_recoverable' => 'boolean',
    ];

    public function line(): BelongsTo
    {
        return $this->belongsTo(BillLine::class, 'bill_line_id');
    }
}
