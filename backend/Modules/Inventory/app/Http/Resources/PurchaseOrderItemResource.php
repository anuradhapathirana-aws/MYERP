<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'po_id'             => $this->po_id,
            'product_id'        => $this->product_id,
            'unit_id'           => $this->unit_id,
            'attribute_id'      => $this->attribute_id,
            'pr_item_id'        => $this->pr_item_id,
            'quantity_ordered'  => (float) $this->quantity_ordered,
            'quantity_received' => (float) $this->quantity_received,
            'remaining_qty'     => $this->remaining_qty_attribute,
            'unit_price'        => (float) $this->unit_price,
            'discount'          => (float) $this->discount,
            'tax'               => (float) $this->tax,
            'line_total'        => (float) $this->line_total,
            'remarks'           => $this->remarks,

            'product' => $this->whenLoaded('product', fn () => [
                'id'           => $this->product->id,
                'name'         => $this->product->name,
                'product_code' => $this->product->product_code,
                'is_batch'     => $this->product->is_batch,
                'is_serial'    => $this->product->is_serial,
            ]),
            'unit' => $this->whenLoaded('unit', fn () => [
                'id'   => $this->unit->id,
                'name' => $this->unit->name,
            ]),
            'attribute' => $this->whenLoaded('attribute', fn () => $this->attribute ? [
                'id'   => $this->attribute->id,
                'name' => $this->attribute->attribute_name,
            ] : null),
        ];
    }
}
