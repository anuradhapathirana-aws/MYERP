<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'po_no'                  => $this->po_no,
            'pr_id'                  => $this->pr_id,
            'supplier_id'            => $this->supplier_id,
            'store_id'               => $this->store_id,
            'location_id'            => $this->location_id,
            'order_date'             => $this->order_date?->toDateString(),
            'expected_delivery_date' => $this->expected_delivery_date?->toDateString(),
            'reference_no'           => $this->reference_no,
            'payment_terms'          => $this->payment_terms,
            'contact_person_name'    => $this->contact_person_name,
            'contact_person_phone'   => $this->contact_person_phone,
            'is_consignment'         => $this->is_consignment,
            'billing_address'        => $this->billing_address,
            'shipping_address'       => $this->shipping_address,
            'status'                 => $this->status->value,
            'status_label'           => $this->status->label(),
            'subtotal'               => (float) $this->subtotal,
            'grand_total'            => (float) $this->grand_total,
            'remarks'                => $this->remarks,

            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id'   => $this->supplier->id,
                'name' => $this->supplier->supplier_name ?? $this->supplier->name ?? '',
            ]),
            'store' => $this->whenLoaded('store', fn () => [
                'id'   => $this->store->id,
                'name' => $this->store->store_name,
            ]),
            'location' => $this->whenLoaded('location', fn () => [
                'id'   => $this->location->id,
                'name' => $this->location->location_name,
            ]),
            'purchase_request' => $this->whenLoaded('purchaseRequest', fn () => [
                'id'    => $this->purchaseRequest->id,
                'pr_no' => $this->purchaseRequest->pr_no,
                'source_store' => $this->purchaseRequest->sourceStore ? [
                    'id'   => $this->purchaseRequest->sourceStore->id,
                    'name' => $this->purchaseRequest->sourceStore->store_name,
                ] : null,
                'source_location' => $this->purchaseRequest->sourceLocation ? [
                    'id'   => $this->purchaseRequest->sourceLocation->id,
                    'name' => $this->purchaseRequest->sourceLocation->location_name ?? $this->purchaseRequest->sourceLocation->name ?? '',
                ] : null,
            ]),

            'items' => $this->when(
                $this->resource->relationLoaded('items'),
                fn () => PurchaseOrderItemResource::collection($this->items),
            ),

            'grn_count' => $this->whenLoaded('goodsReceivedNotes', fn () =>
                $this->goodsReceivedNotes->count()
            ),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
