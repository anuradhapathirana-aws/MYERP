<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var \Modules\Inventory\Models\Store $this */
        return [
            'id'               => $this->id,
            'store_type_id'    => $this->store_type_id,
            'location_id'      => $this->location_id,
            'parent_store_id'  => $this->parent_store_id,
            'store_code'       => $this->store_code,
            'store_name'     => $this->store_name,
            'uom'            => $this->uom,
            'capacity'       => $this->capacity,
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'city'           => $this->city,
            'state'          => $this->state,
            'country'        => $this->country,
            'postal_code'    => $this->postal_code,
            'manager_name'   => $this->manager_name,
            'phone'          => $this->phone,
            'email'          => $this->email,
            'description'    => $this->description,
            'is_active'      => $this->is_active,

            // Eager-loaded relations
            'parent_store' => $this->whenLoaded('parentStore', fn () => $this->parentStore ? [
                'id'   => $this->parentStore->id,
                'name' => $this->parentStore->store_name,
                'code' => $this->parentStore->store_code,
            ] : null),
            'store_type' => $this->whenLoaded('storeType', fn () => [
                'id'   => $this->storeType->id,
                'name' => $this->storeType->store_type_name,
            ]),
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id'   => $this->location->id,
                'name' => $this->location->location_name,
                'code' => $this->location->location_code,
            ] : null),

            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
