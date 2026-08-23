<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReconciliationPiece extends Model
{
    protected $table = 'inv_stock_reconciliation_pieces';

    protected $fillable = [
        'reconciliation_id',
        'grn_item_piece_id',
        'old_weight',
        'new_weight',
    ];

    protected $casts = [
        'reconciliation_id'  => 'integer',
        'grn_item_piece_id'  => 'integer',
        'old_weight'         => 'decimal:6',
        'new_weight'         => 'decimal:6',
    ];

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(StockReconciliation::class, 'reconciliation_id');
    }

    public function piece(): BelongsTo
    {
        return $this->belongsTo(GrnItemPiece::class, 'grn_item_piece_id');
    }
}
