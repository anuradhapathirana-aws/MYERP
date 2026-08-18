<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attribute extends Model
{
    protected $table = 'inv_attributes';

    protected $fillable = [
        'attribute_type_id',
        'attribute_name',
    ];

    protected $casts = [
        'attribute_type_id' => 'integer',
    ];

    public function attributeType(): BelongsTo
    {
        return $this->belongsTo(AttributeType::class, 'attribute_type_id');
    }
}
