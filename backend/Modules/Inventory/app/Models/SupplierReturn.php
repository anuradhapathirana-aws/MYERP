<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\SupplierMaster;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Enums\SupplierReturnStatus;

/**
 * Supplier (purchase) return — goods going back against one confirmed GRN.
 * Confirming it posts stock OUT and debits the supplier: first against the GRN's
 * outstanding, any excess as an open supplier_return credit note.
 */
class SupplierReturn extends Model
{
    use SoftDeletes;

    protected $table = 'inv_supplier_returns';

    protected $fillable = [
        'return_no',
        'return_date',
        'supplier_id',
        'grn_id',
        'store_id',
        'location_id',
        'status',
        'total_amount',
        'applied_to_grn',
        'credit_note_id',
        'remarks',
        'created_by',
        'confirmed_by',
        'confirmed_at',
    ];

    protected $casts = [
        'return_date'    => 'date',
        'confirmed_at'   => 'datetime',
        'supplier_id'    => 'integer',
        'grn_id'         => 'integer',
        'store_id'       => 'integer',
        'location_id'    => 'integer',
        'credit_note_id' => 'integer',
        'created_by'     => 'integer',
        'confirmed_by'   => 'integer',
        'total_amount'   => 'decimal:4',
        'applied_to_grn' => 'decimal:4',
        'status'         => SupplierReturnStatus::class,
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SupplierReturnItem::class, 'return_id');
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(SupplierReturnPiece::class, 'return_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(SupplierMaster::class, 'supplier_id');
    }

    public function grn(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNote::class, 'grn_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(SupplierCreditNote::class, 'credit_note_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
