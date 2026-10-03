<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Supplier (vendor) group — supplies the DEFAULT posting accounts for a
 * supplier's bills. It classifies and defaults; it never restricts what a
 * supplier can be billed for.
 */
class SupplierGroup extends Model
{
    use SoftDeletes;

    protected $table = 'core_supplier_groups';

    protected $fillable = [
        'code',
        'name',
        'description',
        'default_payable_account_id',
        'default_expense_account_id',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'default_payable_account_id' => 'integer',
        'default_expense_account_id' => 'integer',
        'is_active'                  => 'boolean',
        'sort_order'                 => 'integer',
    ];

    public function suppliers(): HasMany
    {
        return $this->hasMany(SupplierMaster::class, 'supplier_group_id');
    }
}
