<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class Industry extends Model
{
    protected $table = 'inv_industries';

    protected $fillable = [
        'name',
        'description',
    ];
}
