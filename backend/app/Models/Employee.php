<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Minimal shared employee record. A future HR module extends this rather than
 * defining its own; Finance uses it for petty cash custodians and payees.
 */
class Employee extends Model
{
    use SoftDeletes;

    protected $table = 'core_employees';

    protected $fillable = [
        'employee_code',
        'employee_name',
        'designation',
        'department',
        'location_id',
        'mobile',
        'email',
        'is_active',
    ];

    protected $casts = [
        'location_id' => 'integer',
        'is_active'   => 'boolean',
    ];

    /** Soft link — inv_locations has no FK to this table. */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }
}
