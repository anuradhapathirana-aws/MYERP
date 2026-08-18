<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderPiece extends Model
{
    protected $table = 'inv_sales_order_pieces';

    protected $fillable = [
        'so_id',
        'so_item_id',
        'piece_id',
        'piece_code',
        'weight',
        'taken_quantity',
        'grn_unit_price',
        'created_by',
    ];

    protected $casts = [
        'so_id'          => 'integer',
        'so_item_id'     => 'integer',
        'piece_id'       => 'integer',
        'weight'         => 'decimal:6',
        'taken_quantity' => 'decimal:6',
        'grn_unit_price' => 'decimal:8',
        'created_by'     => 'integer',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'so_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'so_item_id');
    }

    public function piece(): BelongsTo
    {
        return $this->belongsTo(GrnItemPiece::class, 'piece_id');
    }
}
