<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'invoice_no'       => $this->invoice_no,
            'so_id'            => $this->so_id,
            'do_id'            => $this->do_id,
            'customer_id'      => $this->customer_id,
            'company_id'       => $this->company_id,
            'invoice_date'     => $this->invoice_date?->toDateString(),
            'due_date'         => $this->due_date?->toDateString(),
            'status'           => $this->status->value,
            'status_label'     => $this->status->label(),
            'invoice_type'     => $this->invoice_type ?? 'non_tax',
            'invoice_type_label' => ($this->invoice_type ?? 'non_tax') === 'tax' ? 'Tax Invoice' : 'Non Tax Invoice',
            'subtotal'         => (float) $this->subtotal,
            'transport_charge' => (float) $this->transport_charge,
            'grand_total'      => (float) $this->grand_total,
            'delivery_address' => $this->delivery_address,
            'remarks'          => $this->remarks,

            'mode_of_payment'       => $this->mode_of_payment?->value,
            'mode_of_payment_label' => $this->mode_of_payment?->label(),

            'issued_at'        => $this->issued_at?->toDateTimeString(),
            'paid_at'          => $this->paid_at?->toDateTimeString(),

            'sales_order' => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id'    => $this->salesOrder->id,
                'so_no' => $this->salesOrder->so_no,
            ] : null),
            'delivery_order' => $this->whenLoaded('deliveryOrder', fn () => $this->deliveryOrder ? [
                'id'    => $this->deliveryOrder->id,
                'do_no' => $this->deliveryOrder->do_no,
            ] : null),
            'company' => $this->whenLoaded('company', fn () => $this->company ? [
                'id'   => $this->company->id,
                'name' => $this->company->company_name,
            ] : null),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id'            => $this->customer->id,
                'customer_code' => $this->customer->customer_code,
                'name'          => $this->customer->customer_name,
            ] : null),

            'items' => $this->when(
                $this->resource->relationLoaded('items'),
                fn () => InvoiceItemResource::collection($this->items),
            ),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
