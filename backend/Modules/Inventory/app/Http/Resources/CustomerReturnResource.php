<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerReturnResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'return_no'          => $this->return_no,
            'return_date'        => $this->return_date?->toDateString(),
            'customer_id'        => $this->customer_id,
            'invoice_id'         => $this->invoice_id,
            'do_id'              => $this->do_id,
            'store_id'           => $this->store_id,
            'location_id'        => $this->location_id,
            'status'             => $this->status->value,
            'status_label'       => $this->status->label(),
            'total_amount'       => (float) $this->total_amount,
            'applied_to_invoice' => (float) $this->applied_to_invoice,
            'credit_note_id'     => $this->credit_note_id,
            'remarks'            => $this->remarks,
            'confirmed_at'       => $this->confirmed_at?->toDateTimeString(),

            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id'            => $this->customer->id,
                'customer_code' => $this->customer->customer_code,
                'name'          => $this->customer->customer_name,
            ] : null),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? [
                'id'           => $this->invoice->id,
                'invoice_no'   => $this->invoice->invoice_no,
                'invoice_date' => $this->invoice->invoice_date?->toDateString(),
                'grand_total'  => $this->invoice->grand_total !== null ? (float) $this->invoice->grand_total : null,
                'status'       => $this->invoice->status?->value,
            ] : null),
            'do_no' => $this->whenLoaded('deliveryOrder', fn () => $this->deliveryOrder?->do_no),
            'store' => $this->whenLoaded('store', fn () => $this->store ? [
                'id'         => $this->store->id,
                'store_code' => $this->store->store_code,
                'store_name' => $this->store->store_name,
            ] : null),
            'credit_note' => $this->whenLoaded('creditNote', fn () => $this->creditNote ? [
                'id'                => $this->creditNote->id,
                'credit_note_no'    => $this->creditNote->credit_note_no,
                'amount'            => (float) $this->creditNote->amount,
                'remaining_balance' => (float) $this->creditNote->remaining_balance,
                'status'            => $this->creditNote->status->value,
            ] : null),
            'created_by'   => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'confirmed_by' => $this->whenLoaded('confirmedBy', fn () => $this->confirmedBy?->name),

            'items' => $this->whenLoaded('items', fn ($items) => CustomerReturnItemResource::collection($items)),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
