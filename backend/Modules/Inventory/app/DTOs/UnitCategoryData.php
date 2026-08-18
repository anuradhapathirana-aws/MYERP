<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\UnitCategoryRequest;

final class UnitCategoryData
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description,
    ) {}

    public static function fromRequest(UnitCategoryRequest $request): self
    {
        return new self(
            name: $request->validated('name'),
            description: $request->validated('description'),
        );
    }
}
