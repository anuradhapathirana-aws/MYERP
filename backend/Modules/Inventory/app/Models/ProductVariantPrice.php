<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A variant's own price for one sales channel. Filed in the same unit as the
 * product's price-list row for that channel; a missing price falls back to it.
 */
class ProductVariantPrice extends Model
{
    protected $table = 'inv_product_variant_prices';

    protected $fillable = [
        'variant_id',
        'sales_channel_id',
        'selling_price',
        'wholesale_price',
        'min_price',
        'max_price',
    ];

    protected $casts = [
        'variant_id'       => 'integer',
        'sales_channel_id' => 'integer',
        'selling_price'    => 'decimal:4',
        'wholesale_price'  => 'decimal:4',
        'min_price'        => 'decimal:4',
        'max_price'        => 'decimal:4',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class, 'sales_channel_id');
    }
}
