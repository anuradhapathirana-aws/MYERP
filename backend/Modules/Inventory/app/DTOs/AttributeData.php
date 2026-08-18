<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\AttributeRequest;

final class AttributeData
{
    public function __construct(
        public readonly int $attribute_type_id,
        public readonly string $attribute_name,
    ) {}

    public static function fromRequest(AttributeRequest $request): self
    {
        return new self(
            attribute_type_id: (int) $request->validated('attribute_type_id'),
            attribute_name:    $request->validated('attribute_name'),
        );
    }
}
