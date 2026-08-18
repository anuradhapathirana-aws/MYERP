<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Enums\CostingStatus;
use Modules\Inventory\Enums\CostingType;

class Costing extends Model
{
    use SoftDeletes;

    protected $table = 'inv_costings';

    protected $fillable = [
        'document_no',
        'reference_no',
        'supplier_id',
        'costing_type',
        'total_items',
        'material_cost',
        'bill_of_lading',
        'expected_date',
        'transaction_date',
        'note',
        'total_additional_expenses',
        'raw_material_cost',
        'total_landed_cost',
        'default_margin_pct',
        'value_addition_pct',
        'value_addition_amount',
        'fob_cif_cost',
        'sscl_pct',
        'apply_sscl',
        'sscl_amount',
        'gross_fob_cif_value',
        'vat_pct',
        'apply_vat',
        'vat_amount',
        'total_price_with_vat',
        'status',
        'created_by',
        'confirmed_at',
    ];

    protected $casts = [
        'status'                    => CostingStatus::class,
        'costing_type'              => CostingType::class,
        'expected_date'             => 'date',
        'transaction_date'          => 'date',
        'confirmed_at'              => 'datetime',
        'supplier_id'               => 'integer',
        'created_by'                => 'integer',
        'total_items'               => 'decimal:4',
        'material_cost'             => 'decimal:4',
        'total_additional_expenses' => 'decimal:4',
        'raw_material_cost'         => 'decimal:4',
        'total_landed_cost'         => 'decimal:4',
        'default_margin_pct'        => 'decimal:2',
        'apply_sscl'                => 'boolean',
        'apply_vat'                 => 'boolean',
        'value_addition_pct'        => 'decimal:2',
        'value_addition_amount'     => 'decimal:4',
        'fob_cif_cost'              => 'decimal:4',
        'sscl_pct'                  => 'decimal:2',
        'sscl_amount'               => 'decimal:4',
        'gross_fob_cif_value'       => 'decimal:4',
        'vat_pct'                   => 'decimal:2',
        'vat_amount'                => 'decimal:4',
        'total_price_with_vat'      => 'decimal:4',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(SupplierMaster::class, 'supplier_id');
    }

    public function costingGrns(): HasMany
    {
        return $this->hasMany(CostingGrn::class, 'costing_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(CostingExpense::class, 'costing_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CostingItem::class, 'costing_id');
    }

    /** Per-line expense amounts across all items (flat, for bulk operations). */
    public function itemExpenses(): HasMany
    {
        return $this->hasMany(CostingItemExpense::class, 'costing_id');
    }
}
