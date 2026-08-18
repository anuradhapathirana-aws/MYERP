<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\CategoryRequest;

final class CategoryData
{
    public function __construct(
        public readonly string  $product_service_type,
        public readonly ?int    $industry_id,
        public readonly ?int    $company_id,
        public readonly ?int    $parent_category_id,
        public readonly string  $category_name,
        public readonly ?string $reference_name,
    ) {}

    public static function fromRequest(CategoryRequest $request): self
    {
        return new self(
            product_service_type: $request->input('product_service_type', 'product'),
            industry_id:          $request->filled('industry_id')        ? (int) $request->input('industry_id')        : null,
            company_id:           $request->filled('company_id')         ? (int) $request->input('company_id')         : null,
            parent_category_id:   $request->filled('parent_category_id') ? (int) $request->input('parent_category_id') : null,
            category_name:        trim($request->input('category_name')),
            reference_name:       $request->filled('reference_name')     ? trim($request->input('reference_name'))     : null,
        );
    }
}
