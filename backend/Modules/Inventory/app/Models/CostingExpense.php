<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostingExpense extends Model
{
    protected $table = 'inv_costing_expenses';

    protected $fillable = [
        'costing_id',
        'expense_type_id',
        'amount',
        'note',
    ];

    protected $casts = [
        'costing_id'      => 'integer',
        'expense_type_id' => 'integer',
        'amount'          => 'decimal:4',
    ];

    public function costing(): BelongsTo
    {
        return $this->belongsTo(Costing::class, 'costing_id');
    }

    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(CostingExpenseType::class, 'expense_type_id');
    }
}
