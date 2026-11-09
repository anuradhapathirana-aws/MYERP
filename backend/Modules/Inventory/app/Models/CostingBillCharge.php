<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supplier-bill line pulled into a costing ("Pull from bills"), as it was
 * when pulled. source_id is the bill line in Finance (soft link). Its amount
 * is spread over the costing's products by value; see CostingService.
 * Written only by CostingService.
 */
class CostingBillCharge extends Model
{
    public const SOURCE_BILL_LINE = 'bill_line';

    protected $table = 'inv_costing_bill_charges';

    protected $fillable = [
        'costing_id',
        'source_type',
        'source_id',
        'bill_no',
        'bill_date',
        'party_name',
        'charge_name',
        'grn_id',
        'expense_type_id',
        'amount',
    ];

    protected $casts = [
        'costing_id'      => 'integer',
        'source_id'       => 'integer',
        'bill_date'       => 'date',
        'grn_id'          => 'integer',
        'expense_type_id' => 'integer',
        'amount'          => 'decimal:2',
    ];

    /** Soft-deleted costings drop out, so a deleted draft no longer holds the bill. */
    public function costing(): BelongsTo
    {
        return $this->belongsTo(Costing::class, 'costing_id');
    }

    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(CostingExpenseType::class, 'expense_type_id');
    }
}
