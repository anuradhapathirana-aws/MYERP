<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttributeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'attribute_type_id'    => $this->attribute_type_id,
            'attribute_type_name'          => $this->whenLoaded('attributeType', fn () => $this->attributeType?->attribute_type_name),
            'attribute_type_category_name' => $this->whenLoaded('attributeType', fn () => $this->attributeType?->category?->category_name),
            'attribute_name'       => $this->attribute_name,
            'created_at'           => $this->created_at->toISOString(),
            'updated_at'           => $this->updated_at->toISOString(),
        ];
    }
}
