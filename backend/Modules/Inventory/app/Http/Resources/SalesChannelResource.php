<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesChannelResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var \Modules\Inventory\Models\SalesChannel $this */
        return [
            'id'                 => $this->id,
            'type'               => $this->type?->value,
            'sales_channel_name' => $this->sales_channel_name,
            'max_qty'            => $this->max_qty !== null ? (float) $this->max_qty : null,
            'applicable_from'    => $this->applicable_from?->toDateString(),
            'applicable_to'      => $this->applicable_to?->toDateString(),
            'description'        => $this->description,
            'status'             => $this->status,
            'created_at'         => $this->created_at->toISOString(),
            'updated_at'         => $this->updated_at->toISOString(),
        ];
    }
}
