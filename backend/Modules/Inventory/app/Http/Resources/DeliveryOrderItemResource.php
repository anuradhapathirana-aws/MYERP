<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Support\Quantity;

class DeliveryOrderItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'do_id'      => $this->do_id,
            'so_item_id' => $this->so_item_id,
            'product_id' => $this->product_id,
            'unit_id'    => $this->unit_id,
            'attribute_id' => $this->attribute_id,
            'is_scanned' => $this->is_scanned,
            'quantity'   => (float) $this->quantity,
            'remarks'    => $this->remarks,

            'product' => $this->whenLoaded('product', fn () => [
                'id'           => $this->product->id,
                'name'         => $this->product->name,
                'product_code' => $this->product->product_code,
            ]),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit ? [
                'id'     => $this->unit->id,
                'name'   => $this->unit->name,
                'symbol' => $this->unit->symbol ?? $this->unit->name,
            ] : null),
            // Roll weights are in the product's stocking UOM, the line quantity in the sold
            // UOM — the DO form shows both, so both symbols have to reach it.
            'base_unit' => $this->whenLoaded('product', fn () => $this->product?->baseUnit ? [
                'id'     => $this->product->baseUnit->id,
                'name'   => $this->product->baseUnit->name,
                'symbol' => $this->product->baseUnit->symbol ?? $this->product->baseUnit->name,
            ] : null),
            'conversion_factor' => (float) $this->conversion_factor ?: 1.0,
            'attribute' => $this->whenLoaded('attribute', fn () => $this->attribute ? [
                'id'   => $this->attribute->id,
                'name' => $this->attribute->attribute_name,
            ] : null),

            'pieces' => $this->when(
                $this->resource->relationLoaded('pieces'),
                fn () => $this->pieces->map(fn ($p) => [
                    'id'         => $p->id,
                    'piece_id'   => $p->piece_id,
                    'piece_code' => $p->piece_code,
                    // What the roll holds vs. the slice this sale takes off it.
                    'weight'         => (float) $p->weight,
                    'taken_quantity' => (float) $p->taken_quantity,
                    'is_cut'         => (float) $p->taken_quantity < (float) $p->weight - Quantity::EPSILON,
                    'roll_no'    => $p->relationLoaded('piece') ? $p->piece?->roll_no : null,
                    'store'      => $p->relationLoaded('piece') ? $p->piece?->store?->store_name : null,
                    'location'   => $p->relationLoaded('piece')
                        ? ($p->piece?->location?->location_name ?? $p->piece?->location?->name)
                        : null,
                    'batch_no'   => $p->relationLoaded('piece') ? $p->piece?->batch?->batch_no : null,
                    'grn_no'     => $p->relationLoaded('piece') ? $p->piece?->grn?->grn_no : null,
                ]),
            ),
        ];
    }
}
