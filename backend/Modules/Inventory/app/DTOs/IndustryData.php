<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\IndustryRequest;

final class IndustryData
{
    public function __construct(
        public readonly string  $name,
        public readonly ?string $description,
    ) {}

    public static function fromRequest(IndustryRequest $request): self
    {
        return new self(
            name:        trim($request->input('name')),
            description: $request->filled('description') ? trim($request->input('description')) : null,
        );
    }
}
