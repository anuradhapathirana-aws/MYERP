<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Database\Factories\ProductFactory;
use Modules\Inventory\Enums\StockTracking;
use Modules\Inventory\Models\Category;
use App\Models\Location;
use Modules\Inventory\Models\ProductAttribute;
use Modules\Inventory\Models\ProductLocationStore;
use Modules\Inventory\Models\SalesChannel;
use Modules\Inventory\Models\StockTransaction;
use App\Models\SupplierMaster;
use Modules\Inventory\Models\UnitType;

class Product extends Model
{
    use HasFactory;

    protected $table = 'inv_products';

    protected $fillable = [
        'product_code',
        'reference_no',
        'ean_13',
        'name',
        'display_name',
        'product_type',
        'stock_tracking',
        'description',
        'category_id',
        'location_id',
        'base_unit_type_id',
        'reorder_level',
        'reorder_qty',
        'reorder_period',
        'stock_releasing_method',
        'tracking_type',
        'lock_purchase',
        'allow_complimentary_items',
        'free_issue',
        'allow_minus',
        'not_allow_direct_sale',
        'non_returnable',
        'is_empty',
        'service_charge',
        'loyalty',
        'is_batch',
        'is_serial',
    ];

    protected $casts = [
        'category_id'               => 'integer',
        'location_id'               => 'integer',
        'base_unit_type_id'         => 'integer',
        'stock_tracking'            => StockTracking::class,
        'reorder_level'             => 'decimal:4',
        'reorder_qty'               => 'decimal:4',
        'lock_purchase'             => 'boolean',
        'allow_complimentary_items' => 'boolean',
        'free_issue'                => 'boolean',
        'allow_minus'               => 'boolean',
        'not_allow_direct_sale'     => 'boolean',
        'non_returnable'            => 'boolean',
        'is_empty'                  => 'boolean',
        'service_charge'            => 'boolean',
        'loyalty'                   => 'boolean',
        'is_batch'                  => 'boolean',
        'is_serial'                 => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function locationStores(): HasMany
    {
        return $this->hasMany(ProductLocationStore::class, 'product_id');
    }

    /** The stocking UOM — every stock balance for this product is denominated in it. */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'base_unit_type_id');
    }

    public function stockTransactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class, 'product_id');
    }

    /**
     * Once stock has moved, the base UOM is frozen: changing it would silently
     * reinterpret every existing balance (100 Kg suddenly reading as 100 g).
     *
     * Prefers the withExists('stockTransactions') attribute so listings don't
     * fire one query per row.
     */
    public function hasStockMovements(): bool
    {
        return isset($this->attributes['stock_transactions_exists'])
            ? (bool) $this->attributes['stock_transactions_exists']
            : $this->stockTransactions()->exists();
    }

    /**
     * Roll-tracked products are received as QR-labelled pieces and sold by
     * picking those pieces; quantity-tracked ones move as plain quantities.
     * A freshly-instantiated model (no DB default loaded yet) counts as roll.
     */
    public function isRollTracked(): bool
    {
        return ($this->stock_tracking ?? StockTracking::Roll) === StockTracking::Roll;
    }

    /** 'roll' | 'quantity' — for API payloads. */
    public function stockTrackingValue(): string
    {
        return ($this->stock_tracking ?? StockTracking::Roll)->value;
    }

    /**
     * Of the given product ids, the ones that are roll-tracked — one query, for
     * request validators that check many lines at once.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, int>
     */
    public static function rollTrackedIds(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return static::whereIn('id', $productIds)
            ->where('stock_tracking', StockTracking::Roll->value)
            ->pluck('id')
            ->map('intval')
            ->all();
    }

    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(SupplierMaster::class, 'inv_product_supplier')
            ->withTimestamps();
    }

    public function productAttributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class, 'product_id');
    }

    public function salesChannels(): BelongsToMany
    {
        return $this->belongsToMany(SalesChannel::class, 'inv_product_sales_channels')
            ->withPivot([
                'unit_type_id',
                'num_of_units',
                'cost_price',
                'margin',
                'margin_type',
                'selling_price',
                'max_price',
                'min_price',
                'wholesale_price',
                'sale_privileges_discount',
                'purchasing_privileges_discount',
            ])
            ->withTimestamps();
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
