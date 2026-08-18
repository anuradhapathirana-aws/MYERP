<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Inventory\Enums\SalesChannelType;

class SalesChannel extends Model
{
    protected $table = 'inv_sales_channels';

    protected $fillable = [
        'type',
        'sales_channel_name',
        'max_qty',
        'applicable_from',
        'applicable_to',
        'description',
        'status',
    ];

    protected $casts = [
        'type'             => SalesChannelType::class,
        'max_qty'          => 'decimal:4',
        'applicable_from'  => 'date',
        'applicable_to'    => 'date',
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'inv_product_sales_channels')
            ->withPivot([
                'num_of_units',
                'cost_price',
                'margin',
                'selling_price',
                'max_price',
                'min_price',
                'wholesale_price',
                'sale_privileges_discount',
                'purchasing_privileges_discount',
            ])
            ->withTimestamps();
    }
}
