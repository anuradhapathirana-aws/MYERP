<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaxType;
use Illuminate\Database\Eloquent\Model;

/**
 * One rate period of a tax (see the core_taxes migration). Read rates through
 * App\Services\TaxService, which picks the row in force on a date.
 */
class Tax extends Model
{
    public const VAT    = 'VAT';
    public const SSCL   = 'SSCL';
    public const EXEMPT = 'EXEMPT';

    protected $table = 'core_taxes';

    protected $fillable = [
        'code',
        'name',
        'tax_type',
        'rate',
        'effective_from',
        'is_active',
        'is_system',
        'created_by',
    ];

    protected $casts = [
        'tax_type'       => TaxType::class,
        'rate'           => 'decimal:2',
        'effective_from' => 'date',
        'is_active'      => 'boolean',
        'is_system'      => 'boolean',
    ];
}
