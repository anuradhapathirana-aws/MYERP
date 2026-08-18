<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttributeTypeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'category_id'          => $this->category_id,
            'category_name'        => $this->whenLoaded('category', fn () => $this->category?->category_name),
            'product_service_type' => $this->product_service_type instanceof \Modules\Inventory\Enums\ProductServiceType
                ? $this->product_service_type->value
                : $this->product_service_type,
            'attribute_type_name'  => $this->attribute_type_name,
            'description'          => $this->description,
            'attributes_count'     => $this->whenCounted('attributes'),
            'created_at'           => $this->created_at->toISOString(),
            'updated_at'           => $this->updated_at->toISOString(),
        ];
    }
}
