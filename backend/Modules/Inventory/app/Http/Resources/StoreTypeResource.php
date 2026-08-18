<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreTypeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var \Modules\Inventory\Models\StoreType $this */
        return [
            'id'               => $this->id,
            'store_type_name'  => $this->store_type_name,
            'description'      => $this->description,
            'is_active'        => $this->is_active,
            'created_at'       => $this->created_at->toISOString(),
            'updated_at'       => $this->updated_at->toISOString(),
        ];
    }
}
