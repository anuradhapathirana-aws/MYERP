<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Enums\UnitPosition;
use Modules\Inventory\Http\Requests\UnitTypeRequest;

final class UnitTypeData
{
    public function __construct(
        public readonly int $unitCategoryId,
        public readonly string $name,
        public readonly string $symbol,
        public readonly ?string $country,
        public readonly UnitPosition $unitPosition,
    ) {}

    public static function fromRequest(UnitTypeRequest $request): self
    {
        return new self(
            unitCategoryId: (int) $request->validated('unit_category_id'),
            name:           $request->validated('name'),
            symbol:         $request->validated('symbol'),
            country:        $request->validated('country'),
            unitPosition:   UnitPosition::from($request->validated('unit_position')),
        );
    }
}
