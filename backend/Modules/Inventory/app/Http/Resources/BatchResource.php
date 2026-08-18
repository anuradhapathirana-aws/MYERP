<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BatchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'batch_no'           => $this->batch_no,
            'product_id'         => $this->product_id,
            'supplier_id'        => $this->supplier_id,
            'supplier_batch_no'  => $this->supplier_batch_no,
            'mfg_date'           => $this->mfg_date?->toDateString(),
            'expiry_date'        => $this->expiry_date?->toDateString(),
            'received_date'      => $this->received_date?->toDateString(),
            'initial_qty'        => (float) $this->initial_qty,
            'current_qty'        => (float) $this->current_qty,
            'unit_cost'          => (float) $this->unit_cost,
            'status'             => $this->status->value,
            'status_label'       => $this->status->label(),
            'status_color'       => $this->status->color(),
            'country_of_origin'  => $this->country_of_origin,
            'notes'              => $this->notes,
            'created_at'         => $this->created_at?->toDateTimeString(),

            'product' => $this->whenLoaded('product', fn () => [
                'id'           => $this->product->id,
                'name'         => $this->product->name,
                'product_code' => $this->product->product_code,
            ]),
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id'            => $this->supplier->id,
                'supplier_name' => $this->supplier->supplier_name,
                'supplier_code' => $this->supplier->supplier_code,
            ]),
        ];
    }
}
