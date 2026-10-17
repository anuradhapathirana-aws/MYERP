<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProformaInvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'proforma_no'        => $this->proforma_no,
            'source_type'        => $this->source_type,
            'source_id'          => $this->source_id,
            'so_id'              => $this->so_id,
            'customer_id'        => $this->customer_id,
            'company_id'         => $this->company_id,
            'proforma_date'      => $this->proforma_date?->toDateString(),
            'valid_until'        => $this->valid_until?->toDateString(),
            'is_expired'         => $this->isExpired(),
            'payment_terms'      => $this->payment_terms,
            'status'             => $this->status->value,
            'status_label'       => $this->status->label(),
            'invoice_type'       => $this->invoice_type,
            'invoice_type_label' => $this->invoice_type === 'tax' ? 'Tax' : 'Non Tax',
            'subtotal'           => (float) $this->subtotal,
            'transport_charge'   => (float) $this->transport_charge,
            'grand_total'        => (float) $this->grand_total,
            'delivery_address'   => $this->delivery_address,
            'remarks'            => $this->remarks,

            'issued_at'    => $this->issued_at?->toDateTimeString(),
            'converted_at' => $this->converted_at?->toDateTimeString(),
            'cancelled_at' => $this->cancelled_at?->toDateTimeString(),

            'sales_order' => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id'    => $this->salesOrder->id,
                'so_no' => $this->salesOrder->so_no,
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

            // DO invoices auto-linked to this proforma
            // Sales orders the proforma's lines were recalled into
            'sales_orders' => $this->when(
                $this->resource->getAttribute('linked_sales_orders') !== null,
                fn () => collect($this->resource->getAttribute('linked_sales_orders'))->map(fn ($so) => [
                    'id'         => (int) $so->id,
                    'so_no'      => $so->so_no,
                    'status'     => $so->status,
                    'order_date' => $so->order_date,
                ])->values(),
            ),

            'invoices' => $this->whenLoaded('invoices', fn () => $this->invoices->map(fn ($inv) => [
                'id'           => $inv->id,
                'invoice_no'   => $inv->invoice_no,
                'invoice_date' => $inv->invoice_date?->toDateString(),
                'status'       => $inv->status->value,
                'status_label' => $inv->status->label(),
                'grand_total'  => (float) $inv->grand_total,
                'do_no'        => $inv->deliveryOrder?->do_no,
            ])->values()),

            'items' => $this->when(
                $this->resource->relationLoaded('items'),
                fn () => ProformaInvoiceItemResource::collection($this->items),
            ),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
