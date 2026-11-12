<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierReturnPiece extends Model
{
    protected $table = 'inv_supplier_return_pieces';

    protected $fillable = [
        'return_id',
        'return_item_id',
        'piece_id',
        'piece_code',
        'quantity',
        'returned_quantity',
        'remnant_piece_id',
        'store_id',
        'location_id',
        'batch_id',
        'stock_transaction_id',
    ];

    protected $casts = [
        'return_id'            => 'integer',
        'return_item_id'       => 'integer',
        'piece_id'             => 'integer',
        'remnant_piece_id'     => 'integer',
        'store_id'             => 'integer',
        'location_id'          => 'integer',
        'batch_id'             => 'integer',
        'stock_transaction_id' => 'integer',
        'quantity'             => 'decimal:4',
        'returned_quantity'    => 'decimal:6',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(SupplierReturnItem::class, 'return_item_id');
    }

    public function piece(): BelongsTo
    {
        return $this->belongsTo(GrnItemPiece::class, 'piece_id');
    }

    public function remnantPiece(): BelongsTo
    {
        return $this->belongsTo(GrnItemPiece::class, 'remnant_piece_id');
    }
}
