<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankBranch extends Model
{
    use SoftDeletes;

    protected $table = 'core_bank_branches';

    protected $fillable = [
        'bank_id',
        'branch_name',
        'branch_code',
        'swift_code',
        'address',
        'is_active',
    ];

    protected $casts = [
        'bank_id'   => 'integer',
        'is_active' => 'boolean',
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }
}
