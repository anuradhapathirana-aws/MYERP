<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'do_no'              => $this->do_no,
            'document_date'      => $this->document_date?->toDateString(),
            'so_id'              => $this->so_id,
            'customer_id'        => $this->customer_id,
            'driver_id'          => $this->driver_id,
            'vehicle_id'         => $this->vehicle_id,
            'store_id'           => $this->store_id,
            'location_id'        => $this->location_id,
            'delivery_date'      => $this->delivery_date?->toDateString(),
            'delivery_mode'      => $this->delivery_mode,
            'delivery_vehicle'   => $this->delivery_vehicle,
            'responsible_person' => $this->responsible_person,
            'delivery_address'   => $this->delivery_address,
            'status'             => $this->status->value,
            'status_label'       => $this->status->label(),
            'remarks'            => $this->remarks,
            'confirmed_at'       => $this->confirmed_at?->toDateTimeString(),

            'total_quantity' => $this->when(
                $this->total_quantity !== null || $this->resource->relationLoaded('items'),
                fn () => (float) ($this->total_quantity ?? $this->items->sum('quantity')),
            ),

            'sales_order' => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id'               => $this->salesOrder->id,
                'so_no'            => $this->salesOrder->so_no,
                'status'           => $this->salesOrder->status->value,
                'customer_type'    => $this->salesOrder->customer_type,
                'order_source'     => $this->salesOrder->order_source,
                'transaction_date' => $this->salesOrder->transaction_date?->toDateString(),
                'sales_person'     => $this->salesOrder->relationLoaded('salesPerson') ? $this->salesOrder->salesPerson?->name : null,
                'order_taken_by'   => $this->salesOrder->relationLoaded('orderTakenBy') ? $this->salesOrder->orderTakenBy?->name : null,
            ] : null),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id'            => $this->customer->id,
                'customer_code' => $this->customer->customer_code,
                'name'          => $this->customer->customer_name,
            ] : null),
            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? [
                'id'   => $this->driver->id,
                'name' => trim("{$this->driver->first_name} {$this->driver->last_name}"),
            ] : null),
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id'                  => $this->vehicle->id,
                'registration_number' => $this->vehicle->registration_number,
            ] : null),
            'store' => $this->whenLoaded('store', fn () => $this->store ? [
                'id'   => $this->store->id,
                'name' => $this->store->store_name,
            ] : null),
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id'   => $this->location->id,
                'name' => $this->location->location_name ?? $this->location->name,
            ] : null),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id'         => $this->invoice->id,
                'invoice_no' => $this->invoice->invoice_no,
                'status'     => $this->invoice->status->value,
            ] : null),

            'items' => $this->when(
                $this->resource->relationLoaded('items'),
                fn () => DeliveryOrderItemResource::collection($this->items),
            ),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
