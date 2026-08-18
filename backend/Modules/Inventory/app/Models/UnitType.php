<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Database\Factories\UnitTypeFactory;
use Modules\Inventory\Enums\UnitPosition;

class UnitType extends Model
{
    use HasFactory;

    protected $table = 'inv_unit_types';

    protected $fillable = [
        'unit_category_id',
        'name',
        'symbol',
        'country',
        'unit_position',
    ];

    protected function casts(): array
    {
        return [
            'unit_category_id' => 'integer',
            'unit_position'    => UnitPosition::class,
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(UnitCategory::class, 'unit_category_id');
    }

    public function conversionsFrom(): HasMany
    {
        return $this->hasMany(UnitConversion::class, 'from_unit_type_id');
    }

    public function conversionsTo(): HasMany
    {
        return $this->hasMany(UnitConversion::class, 'to_unit_type_id');
    }

    protected static function newFactory(): UnitTypeFactory
    {
        return UnitTypeFactory::new();
    }
}
