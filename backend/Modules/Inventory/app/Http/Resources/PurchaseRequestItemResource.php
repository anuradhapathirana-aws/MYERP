<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequestItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'pr_id'                 => $this->pr_id,
            'product_id'            => $this->product_id,
            'unit_id'               => $this->unit_id,
            'attribute_id'          => $this->attribute_id,
            'quantity'              => (float) $this->quantity,
            'estimated_unit_price'  => $this->estimated_unit_price !== null
                                           ? (float) $this->estimated_unit_price
                                           : null,
            'remarks'               => $this->remarks,

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
