<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerReturnItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'invoice_item_id' => $this->invoice_item_id,
            'product_id'      => $this->product_id,
            'product_code'    => $this->whenLoaded('product', fn () => $this->product?->product_code),
            'product_name'    => $this->whenLoaded('product', fn () => $this->product?->name),
            'attribute_name'  => $this->whenLoaded('attribute', fn () => $this->attribute?->attribute_name),
            'unit_name'       => $this->whenLoaded('unit', fn () => $this->unit?->symbol ?: $this->unit?->name),
            'quantity'        => (float) $this->quantity,
            'base_quantity'   => (float) $this->base_quantity,
            'unit_price'      => (float) $this->unit_price,
            'discount'        => (float) $this->discount,
            'tax'             => (float) $this->tax,
            'line_total'      => (float) $this->line_total,
            'store_id'        => $this->store_id,
            'store_name'      => $this->whenLoaded('store', fn () => $this->store?->store_name),
            'condition'       => $this->condition->value,
            'condition_label' => $this->condition->label(),
            'reason'          => $this->reason->value,
            'reason_label'    => $this->reason->label(),
            'remarks'         => $this->remarks,
            'pieces'          => $this->whenLoaded('pieces', fn ($pieces) => $pieces->map(fn ($piece) => [
                'id'                  => $piece->id,
                'do_piece_id'         => $piece->do_piece_id,
                'piece_code'          => $piece->piece_code,
                'quantity'            => (float) $piece->quantity,
                'returned_quantity'   => (float) $piece->returned_quantity,
                'restored_piece_code' => $piece->relationLoaded('restoredPiece') ? $piece->restoredPiece?->piece_code : null,
            ])->values()),
        ];
    }
}
