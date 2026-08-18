<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrnItemBatch extends Model
{
    protected $table = 'inv_grn_item_batches';

    protected $fillable = [
        'grn_item_id',
        'batch_id',
        'quantity',
        'unit_cost',
    ];

    protected $casts = [
        'grn_item_id' => 'integer',
        'batch_id'    => 'integer',
        'quantity'    => 'decimal:4',
        'unit_cost'   => 'decimal:4',
    ];

    public function grnItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNoteItem::class, 'grn_item_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }
}
