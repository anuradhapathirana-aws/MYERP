<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReturnPiece extends Model
{
    protected $table = 'inv_customer_return_pieces';

    protected $fillable = [
        'return_id',
        'return_item_id',
        'do_piece_id',
        'piece_id',
        'piece_code',
        'quantity',
        'returned_quantity',
        'restored_piece_id',
        'store_id',
        'location_id',
        'batch_id',
        'stock_transaction_id',
    ];

    protected $casts = [
        'return_id'            => 'integer',
        'return_item_id'       => 'integer',
        'do_piece_id'          => 'integer',
        'piece_id'             => 'integer',
        'restored_piece_id'    => 'integer',
        'store_id'             => 'integer',
        'location_id'          => 'integer',
        'batch_id'             => 'integer',
        'stock_transaction_id' => 'integer',
        'quantity'             => 'decimal:4',
        'returned_quantity'    => 'decimal:6',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(CustomerReturnItem::class, 'return_item_id');
    }

    public function doPiece(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrderPiece::class, 'do_piece_id');
    }

    public function restoredPiece(): BelongsTo
    {
        return $this->belongsTo(GrnItemPiece::class, 'restored_piece_id');
    }
}
