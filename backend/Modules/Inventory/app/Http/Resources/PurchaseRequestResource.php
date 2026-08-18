<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'pr_no'            => $this->pr_no,
            'reference_no'     => $this->reference_no,
            'request_date'     => $this->request_date?->toDateString(),
            'required_date'    => $this->required_date?->toDateString(),
            'purpose'          => $this->purpose,
            'transport_mode'   => $this->transport_mode,
            'remarks'          => $this->remarks,
            'status'           => $this->status->value,
            'status_label'     => $this->status->label(),
            'approved_at'      => $this->approved_at?->toDateTimeString(),
            'rejection_reason' => $this->rejection_reason,

            'source_location_id' => $this->source_location_id,
            'source_store_id'    => $this->source_store_id,
            'target_location_id' => $this->target_location_id,
            'target_store_id'    => $this->target_store_id,
            'customer_id'        => $this->customer_id,

            'source_location' => $this->whenLoaded('sourceLocation', fn () => [
                'id'   => $this->sourceLocation->id,
                'name' => $this->sourceLocation->name ?? $this->sourceLocation->location_name ?? '',
            ]),
            'source_store' => $this->whenLoaded('sourceStore', fn () => [
                'id'   => $this->sourceStore->id,
                'name' => $this->sourceStore->store_name,
            ]),
            'target_location' => $this->whenLoaded('targetLocation', fn () => [
                'id'   => $this->targetLocation->id,
                'name' => $this->targetLocation->name ?? $this->targetLocation->location_name ?? '',
            ]),
            'target_store' => $this->whenLoaded('targetStore', fn () => [
                'id'   => $this->targetStore->id,
                'name' => $this->targetStore->store_name,
            ]),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id'   => $this->customer->id,
                'name' => $this->customer->customer_name,
            ] : null),

            'items' => $this->whenLoaded('items', fn ($items) => PurchaseRequestItemResource::collection($items)),

            'total_quantity' => $this->whenLoaded('items', fn () =>
                $this->items->sum('quantity')
            ),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
