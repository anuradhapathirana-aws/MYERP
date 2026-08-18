<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Enums\UnitPosition;

class UnitTypeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var \Modules\Inventory\Models\UnitType $this */
        return [
            'id'               => $this->id,
            'unit_category_id' => $this->unit_category_id,
            'category'         => $this->whenLoaded('category', fn () => [
                'id'   => $this->category->id,
                'name' => $this->category->name,
            ]),
            'name'          => $this->name,
            'symbol'        => $this->symbol,
            'country'       => $this->country,
            'unit_position' => $this->unit_position instanceof UnitPosition
                ? $this->unit_position->value
                : $this->unit_position,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
