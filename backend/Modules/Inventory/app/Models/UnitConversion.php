<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitConversion extends Model
{
    protected $table = 'inv_unit_conversions';

    protected $fillable = [
        'from_unit_type_id',
        'to_unit_type_id',
        'multiplier',
    ];

    protected function casts(): array
    {
        return [
            'from_unit_type_id' => 'integer',
            'to_unit_type_id'   => 'integer',
            'multiplier'        => 'decimal:10',
        ];
    }

    public function fromUnit(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'from_unit_type_id');
    }

    public function toUnit(): BelongsTo
    {
        return $this->belongsTo(UnitType::class, 'to_unit_type_id');
    }
}
