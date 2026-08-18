<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestItem extends Model
{
    protected $table = 'inv_purchase_request_items';

    protected $fillable = [
        'pr_id',
        'product_id',
        'unit_id',
        'attribute_id',
        'quantity',
        'estimated_unit_price',
        'remarks',
    ];

    protected $casts = [
        'pr_id'                => 'integer',
        'product_id'           => 'integer',
        'unit_id'              => 'integer',
        'attribute_id'         => 'integer',
        'quantity'             => 'decimal:4',
        'estimated_unit_price' => 'decimal:4',
    ];

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'pr_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'unit_id');
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }
}
