<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use SoftDeletes;

    protected $table = 'inv_locations';

    protected $fillable = [
        'company_id',
        'industry_id',
        'parent_location_id',
        'location_code',
        'location_name',
        'location_type',
        'country',
        'loc_street_address',
        'loc_city',
        'loc_country',
        'loc_state',
        'loc_postal_zip_code',
        'billing_same_as_location',
        'bill_street_address',
        'bill_city',
        'bill_country',
        'bill_state',
        'bill_postal_zip_code',
        'company_email',
        'customer_facing_email',
        'company_phone',
        'mobile',
        'fax',
        'website',
        'longitude',
        'latitude',
        'map_url',
        'date_format',
        'number_format',
        'time_format',
        'float_precision',
        'base_currency',
        'time_zone',
        'financial_year',
        'open_hours_from',
        'open_hours_to',
        'available_modules',
        'stock_releasing_method',
        'logo_path',
        'header_path',
        'footer_path',
    ];

    protected $casts = [
        'billing_same_as_location' => 'boolean',
        'available_modules'        => 'array',
        'longitude'                => 'decimal:8',
        'latitude'                 => 'decimal:8',
        'float_precision'          => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
    }

    public function parentLocation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_location_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'location_id');
    }
}
