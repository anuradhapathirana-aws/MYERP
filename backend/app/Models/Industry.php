<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Industry extends Model
{
    protected $table = 'inv_industries';

    protected $fillable = [
        'name',
        'description',
    ];
}
