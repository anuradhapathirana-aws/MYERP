<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Driver extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'inv_drivers';

    protected $fillable = [
        'driver_code',
        'first_name',
        'last_name',
        'nic_number',
        'date_of_birth',
        'license_number',
        'license_type',
        'license_expiry_date',
        'phone',
        'email',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'country',
        'postal_code',
        'hired_date',
        'status',
        'notes',
    ];

    protected $casts = [
        'date_of_birth'       => 'date',
        'license_expiry_date' => 'date',
        'hired_date'          => 'date',
    ];
}
