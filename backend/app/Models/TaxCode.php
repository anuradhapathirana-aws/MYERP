<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaxCodeScope;
use App\Enums\VatCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tax code staff pick on a document line (see the core_tax_codes migration).
 * Its amounts are worked out by App\Services\TaxCalculator.
 */
class TaxCode extends Model
{
    protected $table = 'core_tax_codes';

    protected $fillable = [
        'code',
        'name',
        'scope',
        'vat_category',
        'is_active',
        'is_system',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'scope'        => TaxCodeScope::class,
        'vat_category' => VatCategory::class,
        'is_active'    => 'boolean',
        'is_system'    => 'boolean',
        'sort_order'   => 'integer',
    ];

    public function components(): HasMany
    {
        return $this->hasMany(TaxCodeComponent::class)->orderBy('sequence');
    }

    public function scopeForPurchases(Builder $query): Builder
    {
        return $query->where('scope', TaxCodeScope::Purchase->value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
