<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Models\Product;

class Category extends Model
{
    protected $table = 'inv_categories';

    protected $fillable = [
        'product_service_type',
        'industry_id',
        'company_id',
        'parent_category_id',
        'category_name',
        'reference_name',
    ];

    protected $casts = [
        'industry_id'        => 'integer',
        'company_id'         => 'integer',
        'parent_category_id' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_category_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_category_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class, 'industry_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** Collect all descendant IDs (for circular-reference guard). */
    public function descendantIds(): array
    {
        $ids      = [];
        $children = $this->children()->get(['id']);

        foreach ($children as $child) {
            $ids[] = $child->id;
            array_push($ids, ...$child->descendantIds());
        }

        return $ids;
    }
}
