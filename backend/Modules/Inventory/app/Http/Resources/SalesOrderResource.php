<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'so_no'            => $this->so_no,
            'reference_no'     => $this->reference_no,
            'customer_id'      => $this->customer_id,
            'sales_person_id'  => $this->sales_person_id,
            'order_taken_by'   => $this->order_taken_by,
            'customer_type'    => $this->customer_type,
            'order_date'       => $this->order_date?->toDateString(),
            'expected_date'    => $this->expected_date?->toDateString(),
            'transaction_date' => $this->transaction_date?->toDateString(),
            'order_source'     => $this->order_source,
            'delivery_address' => $this->delivery_address,
            'status'           => $this->status->value,
            'status_label'     => $this->status->label(),
            'subtotal'         => (float) $this->subtotal,
            'transport_charge' => (float) $this->transport_charge,
            'grand_total'      => (float) $this->grand_total,
            'remarks'          => $this->remarks,

            'total_quantity' => $this->when(
                $this->total_quantity !== null || $this->resource->relationLoaded('items'),
                fn () => (float) ($this->total_quantity ?? $this->items->sum('quantity')),
            ),

            'customer' => $this->whenLoaded('customer', fn () => [
                'id'            => $this->customer->id,
                'customer_code' => $this->customer->customer_code,
                'name'          => $this->customer->customer_name,
                'customer_type' => $this->customer->customer_type,
            ]),
            'sales_person' => $this->whenLoaded('salesPerson', fn () => $this->salesPerson ? [
                'id'   => $this->salesPerson->id,
                'name' => $this->salesPerson->name,
            ] : null),
            'order_taken_by_user' => $this->whenLoaded('orderTakenBy', fn () => $this->orderTakenBy ? [
                'id'   => $this->orderTakenBy->id,
                'name' => $this->orderTakenBy->name,
            ] : null),

            'items' => $this->when(
                $this->resource->relationLoaded('items'),
                fn () => SalesOrderItemResource::collection($this->items),
            ),

            'delivery_orders' => $this->whenLoaded('deliveryOrders', fn () =>
                $this->deliveryOrders->map(fn ($do) => [
                    'id'            => $do->id,
                    'do_no'         => $do->do_no,
                    'status'        => $do->status->value,
                    'status_label'  => $do->status->label(),
                    'delivery_date' => $do->delivery_date?->toDateString(),
                ])->values(),
            ),
            'invoices' => $this->whenLoaded('invoices', fn () =>
                $this->invoices->map(fn ($inv) => [
                    'id'           => $inv->id,
                    'invoice_no'   => $inv->invoice_no,
                    'status'       => $inv->status->value,
                    'status_label' => $inv->status->label(),
                    'do_id'        => $inv->do_id,
                    'grand_total'  => (float) $inv->grand_total,
                ])->values(),
            ),
            // null = not billed yet; 'direct' = advance invoice; 'per_do' = per-delivery invoices
            'billing_mode' => $this->whenLoaded('invoices', function () {
                $live = $this->invoices->filter(fn ($inv) => $inv->status->value !== 'cancelled');
                if ($live->isEmpty()) {
                    return null;
                }

                return $live->contains(fn ($inv) => $inv->do_id === null) ? 'direct' : 'per_do';
            }),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
