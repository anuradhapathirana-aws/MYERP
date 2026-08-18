<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One typed expense amount of a costing line — PER BASE (stocking) UNIT of the
 * product, against one expense type of the costing's FOB/CIF list. Rebuilt
 * together with its parent inv_costing_items row on every draft edit.
 */
class CostingItemExpense extends Model
{
    protected $table = 'inv_costing_item_expenses';

    protected $fillable = [
        'costing_id',
        'costing_item_id',
        'grn_item_id',
        'expense_type_id',
        'amount',
    ];

    protected $casts = [
        'costing_id'      => 'integer',
        'costing_item_id' => 'integer',
        'grn_item_id'     => 'integer',
        'expense_type_id' => 'integer',
        'amount'          => 'decimal:8',
    ];

    public function costing(): BelongsTo
    {
        return $this->belongsTo(Costing::class, 'costing_id');
    }

    public function costingItem(): BelongsTo
    {
        return $this->belongsTo(CostingItem::class, 'costing_item_id');
    }

    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(CostingExpenseType::class, 'expense_type_id');
    }
}
