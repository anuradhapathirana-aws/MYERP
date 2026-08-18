<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Company extends Model
{
    protected $table = 'inv_companies';

    protected $fillable = [
        'company_type',
        'company_name',
        'registration_no',
        'tax_reg_no',
        'street_address',
        'city',
        'country',
        'state',
        'postal_zip_code',
        'company_email',
        'company_email_2',
        'company_mobile',
        'industry_id',
    ];

    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
    }
}
