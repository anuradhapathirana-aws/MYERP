<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceivedNoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'grn_no'           => $this->grn_no,
            'reference_no'     => $this->reference_no,
            'shipping_code'    => $this->shipping_code,
            'po_id'            => $this->po_id,
            'supplier_id'      => $this->supplier_id,
            'grn_date'         => $this->grn_date?->toDateString(),
            'transaction_date' => $this->transaction_date?->toDateString(),
            'store_id'         => $this->store_id,
            'location_id'      => $this->location_id,
            'status'           => $this->status->value,
            'status_label'     => $this->status->label(),
            'total_amount'     => (float) $this->total_amount,
            'remarks'          => $this->remarks,
            'payment_terms'    => $this->payment_terms,
            'attachments'      => $this->attachments ?? [],
            'confirmed_at'     => $this->confirmed_at?->toDateTimeString(),

            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => [
                'id'    => $this->purchaseOrder->id,
                'po_no' => $this->purchaseOrder->po_no,
            ]),
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id'            => $this->supplier->id,
                'name'          => $this->supplier->supplier_name ?? $this->supplier->name ?? '',
                'supplier_code' => $this->supplier->supplier_code ?? '',
            ]),
            'store' => $this->whenLoaded('store', fn () => [
                'id'   => $this->store->id,
                'name' => $this->store->store_name,
            ]),
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id'   => $this->location->id,
                'name' => $this->location->name ?? $this->location->location_name ?? '',
            ] : null),

            'items' => $this->whenLoaded('items', fn ($items) => GoodsReceivedNoteItemResource::collection($items)),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
