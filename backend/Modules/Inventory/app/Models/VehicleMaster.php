<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleMaster extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'inv_vehicle_masters';

    protected $fillable = [
        'vehicle_code',
        'registration_number',
        'make',
        'model',
        'year',
        'color',
        'vehicle_type',
        'fuel_type',
        'engine_number',
        'chassis_number',
        'seating_capacity',
        'payload_capacity',
        'insurance_policy_no',
        'insurance_expiry_date',
        'road_tax_expiry_date',
        'emission_test_expiry_date',
        'assigned_driver_id',
        'status',
        'notes',
    ];

    protected $casts = [
        'year'                      => 'integer',
        'seating_capacity'          => 'integer',
        'payload_capacity'          => 'decimal:2',
        'insurance_expiry_date'     => 'date',
        'road_tax_expiry_date'      => 'date',
        'emission_test_expiry_date' => 'date',
    ];

    /** Soft reference to the currently assigned driver. */
    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'assigned_driver_id');
    }
}
