<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Enums\GrnStatus;

class GoodsReceivedNote extends Model
{
    use SoftDeletes;

    protected $table = 'inv_goods_received_notes';

    protected $fillable = [
        'grn_no',
        'reference_no',
        'shipping_code',
        'po_id',
        'supplier_id',
        'grn_date',
        'transaction_date',
        'store_id',
        'location_id',
        'status',
        'total_amount',
        'remarks',
        'payment_terms',
        'attachments',
        'received_by',
        'confirmed_at',
    ];

    protected $casts = [
        'grn_date'         => 'date',
        'transaction_date' => 'date',
        'confirmed_at'     => 'datetime',
        'total_amount'     => 'decimal:4',
        'po_id'            => 'integer',
        'supplier_id'      => 'integer',
        'store_id'         => 'integer',
        'location_id'      => 'integer',
        'received_by'      => 'integer',
        'status'           => GrnStatus::class,
        'attachments'      => 'array',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceivedNoteItem::class, 'grn_id');
    }

    public function attachmentFiles(): HasMany
    {
        return $this->hasMany(GrnAttachment::class, 'grn_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(SupplierMaster::class, 'supplier_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
