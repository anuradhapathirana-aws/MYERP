<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Enums\CostingType;

class CostingExpenseType extends Model
{
    protected $table = 'inv_costing_expense_types';

    protected $fillable = [
        'name',
        'costing_type',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'costing_type' => CostingType::class,
        'is_active'    => 'boolean',
        'sort_order'   => 'integer',
    ];

    public function expenses(): HasMany
    {
        return $this->hasMany(CostingExpense::class, 'expense_type_id');
    }

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForType(\Illuminate\Database\Eloquent\Builder $query, string $type): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('costing_type', $type);
    }
}
