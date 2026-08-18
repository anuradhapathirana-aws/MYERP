<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GrnItemPieceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'piece_code' => $this->piece_code,
            'piece_no'   => $this->piece_no,
            'weight'     => $this->weight !== null ? (float) $this->weight : null,
            'roll_no'    => $this->roll_no,
            'status'     => $this->status,
            'printed_at' => $this->printed_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),

            'product' => $this->whenLoaded('product', fn () => [
                'id'           => $this->product->id,
                'name'         => $this->product->name,
                'product_code' => $this->product->product_code,
            ]),
            'batch' => $this->whenLoaded('batch', fn () => $this->batch ? [
                'id'          => $this->batch->id,
                'batch_no'    => $this->batch->batch_no,
                'expiry_date' => $this->batch->expiry_date?->toDateString(),
            ] : null),
            'store' => $this->whenLoaded('store', fn () => $this->store ? [
                'id'         => $this->store->id,
                'store_name' => $this->store->store_name,
            ] : null),
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id'   => $this->location->id,
                'name' => $this->location->location_name,
            ] : null),
            'grn' => $this->whenLoaded('grn', fn () => [
                'id'            => $this->grn->id,
                'grn_no'        => $this->grn->grn_no,
                'grn_date'      => $this->grn->grn_date?->toDateString(),
                'status'        => $this->grn->status->value,
                'shipping_code' => $this->grn->shipping_code,
            ]),
        ];
    }
}
