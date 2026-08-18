<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\AttributeTypeRequest;

final class AttributeTypeData
{
    public function __construct(
        public readonly int $category_id,
        public readonly string $product_service_type,
        public readonly string $attribute_type_name,
        public readonly ?string $description,
    ) {}

    public static function fromRequest(AttributeTypeRequest $request): self
    {
        return new self(
            category_id:           (int) $request->validated('category_id'),
            product_service_type:  $request->validated('product_service_type'),
            attribute_type_name:   $request->validated('attribute_type_name'),
            description:           $request->validated('description'),
        );
    }
}
