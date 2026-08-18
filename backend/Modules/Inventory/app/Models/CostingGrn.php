<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostingGrn extends Model
{
    protected $table = 'inv_costing_grns';

    protected $fillable = [
        'costing_id',
        'grn_id',
        'grn_total',
    ];

    protected $casts = [
        'costing_id' => 'integer',
        'grn_id'     => 'integer',
        'grn_total'  => 'decimal:4',
    ];

    public function costing(): BelongsTo
    {
        return $this->belongsTo(Costing::class, 'costing_id');
    }

    public function grn(): BelongsTo
    {
        return $this->belongsTo(GoodsReceivedNote::class, 'grn_id');
    }
}
