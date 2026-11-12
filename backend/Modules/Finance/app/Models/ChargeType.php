<?php

declare(strict_types=1);

namespace Modules\Finance\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\Enums\ChargeTreatment;

/**
 * What a bill line is for — Freight, Customs Duty, Import VAT... — and so where
 * its money goes (see ChargeTreatment). Written only by ChargeTypeService.
 *
 * fob_expense_type_id / cif_expense_type_id are soft links to Inventory's
 * Costing rows: which row a pulled charge fills on a FOB or a CIF costing.
 */
class ChargeType extends Model
{
    protected $table = 'fin_charge_types';

    protected $fillable = [
        'code',
        'name',
        'treatment',
        'ledger_account_id',
        'fob_expense_type_id',
        'cif_expense_type_id',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'treatment'           => ChargeTreatment::class,
        'ledger_account_id'   => 'integer',
        'fob_expense_type_id' => 'integer',
        'cif_expense_type_id' => 'integer',
        'is_active'           => 'boolean',
        'is_system'           => 'boolean',
        'sort_order'          => 'integer',
        'created_by'          => 'integer',
    ];

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
