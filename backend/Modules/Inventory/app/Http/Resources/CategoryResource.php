<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var \Modules\Inventory\Models\Category $this */
        return [
            'id'                   => $this->id,
            'product_service_type' => $this->product_service_type,
            'industry_id'          => $this->industry_id,
            'company_id'           => $this->company_id,
            'parent_category_id'   => $this->parent_category_id,
            'parent_category_name' => $this->whenLoaded('parent', fn () => $this->parent?->category_name),
            'category_name'        => $this->category_name,
            'reference_name'       => $this->reference_name,
            'industry_name'        => $this->whenLoaded('industry', fn () => $this->industry?->name),
            'company_name'         => $this->whenLoaded('company', fn () => $this->company?->company_name),
            'created_at'           => $this->created_at->toISOString(),
            'updated_at'           => $this->updated_at->toISOString(),
        ];
    }
}
