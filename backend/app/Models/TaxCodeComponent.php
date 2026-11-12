<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One tax inside a tax code, applied in `sequence` order. */
class TaxCodeComponent extends Model
{
    protected $table = 'core_tax_code_components';

    protected $fillable = [
        'tax_code_id',
        'tax_code_ref',
        'sequence',
        'is_compound',
        'is_recoverable',
    ];

    protected $casts = [
        'sequence'       => 'integer',
        'is_compound'    => 'boolean',
        'is_recoverable' => 'boolean',
    ];

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }
}
