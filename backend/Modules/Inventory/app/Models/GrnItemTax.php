<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One tax on one GRN line, as the line's tax code worked it out at save. */
class GrnItemTax extends Model
{
    protected $table = 'inv_grn_item_taxes';

    protected $fillable = [
        'grn_item_id',
        'tax_code_ref',
        'rate',
        'base',
        'amount',
        'is_recoverable',
    ];

    protected $casts = [
        'grn_item_id'    => 'integer',
        'rate'           => 'decimal:2',
        'base'           => 'decimal:2',
        'amount'         => 'decimal:2',
        'is_recoverable' => 'boolean',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNoteItem::class, 'grn_item_id');
    }
}
