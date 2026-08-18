<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentMode extends Model
{
    use SoftDeletes;

    protected $table = 'inv_payment_modes';

    protected $fillable = [
        'payment_mode_name',
        'code',
        'requires_bank_details',
        'requires_reference_no',
        'requires_date',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'requires_bank_details' => 'boolean',
        'requires_reference_no' => 'boolean',
        'requires_date'         => 'boolean',
        'sort_order'            => 'integer',
        'is_active'             => 'boolean',
    ];
}
