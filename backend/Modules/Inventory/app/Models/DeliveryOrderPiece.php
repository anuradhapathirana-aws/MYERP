<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryOrderPiece extends Model
{
    protected $table = 'inv_delivery_order_pieces';

    protected $fillable = [
        'do_id',
        'do_item_id',
        'so_piece_id',
        'piece_id',
        'piece_code',
        'weight',
        'taken_quantity',
        'store_id',
        'location_id',
        'batch_id',
        'stock_transaction_id',
        'created_by',
    ];

    protected $casts = [
        'do_id'                => 'integer',
        'do_item_id'           => 'integer',
        'so_piece_id'          => 'integer',
        'piece_id'             => 'integer',
        'weight'               => 'decimal:6',
        'taken_quantity'       => 'decimal:6',
        'store_id'             => 'integer',
        'location_id'          => 'integer',
        'batch_id'             => 'integer',
        'stock_transaction_id' => 'integer',
        'created_by'           => 'integer',
    ];

    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class, 'do_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrderItem::class, 'do_item_id');
    }

    public function soPiece(): BelongsTo
    {
        return $this->belongsTo(SalesOrderPiece::class, 'so_piece_id');
    }

    public function piece(): BelongsTo
    {
        return $this->belongsTo(GrnItemPiece::class, 'piece_id');
    }

    public function stockTransaction(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'stock_transaction_id');
    }
}
