<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    use SoftDeletes;

    protected $table = 'inv_stores';

    protected $fillable = [
        'store_type_id',
        'location_id',
        'parent_store_id',
        'store_code',
        'store_name',
        'uom',
        'capacity',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'country',
        'postal_code',
        'manager_name',
        'phone',
        'email',
        'description',
        'is_active',
    ];

    protected $casts = [
        'store_type_id'   => 'integer',
        'location_id'     => 'integer',
        'parent_store_id' => 'integer',
        'capacity'        => 'decimal:4',
        'is_active'       => 'boolean',
    ];

    public function storeType(): BelongsTo
    {
        return $this->belongsTo(StoreType::class, 'store_type_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function parentStore(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_store_id');
    }
}
