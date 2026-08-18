<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrnItemPiece extends Model
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_IN_STOCK  = 'in_stock';
    public const STATUS_ALLOCATED = 'allocated'; // reserved by a sales order
    public const STATUS_DELIVERED = 'delivered'; // shipped out via a delivery order

    protected $table = 'inv_grn_item_pieces';

    protected $fillable = [
        'grn_item_id',
        'grn_id',
        'product_id',
        'batch_id',
        'stock_transaction_id',
        'store_id',
        'location_id',
        'piece_no',
        'parent_piece_id',
        'weight',
        'roll_no',
        'piece_code',
        'status',
        'printed_at',
        'created_by',
    ];

    protected $casts = [
        'grn_item_id'           => 'integer',
        'grn_id'                => 'integer',
        'product_id'            => 'integer',
        'batch_id'              => 'integer',
        'stock_transaction_id'  => 'integer',
        'store_id'              => 'integer',
        'location_id'           => 'integer',
        'piece_no'              => 'integer',
        'parent_piece_id'       => 'integer',
        'weight'                => 'decimal:6',
        'printed_at'            => 'datetime',
        'created_by'            => 'integer',
    ];

    public function grnItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNoteItem::class, 'grn_item_id');
    }

    /** The roll this one was cut from — null for rolls received on the GRN. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_piece_id');
    }

    public function grn(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNote::class, 'grn_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function stockTransaction(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'stock_transaction_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
