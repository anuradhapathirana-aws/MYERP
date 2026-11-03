<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierReturnResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'return_no'      => $this->return_no,
            'return_date'    => $this->return_date?->toDateString(),
            'supplier_id'    => $this->supplier_id,
            'grn_id'         => $this->grn_id,
            'store_id'       => $this->store_id,
            'location_id'    => $this->location_id,
            'status'         => $this->status->value,
            'status_label'   => $this->status->label(),
            'total_amount'   => (float) $this->total_amount,
            'applied_to_grn' => (float) $this->applied_to_grn,
            'credit_note_id' => $this->credit_note_id,
            'remarks'        => $this->remarks,
            'confirmed_at'   => $this->confirmed_at?->toDateTimeString(),

            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier ? [
                'id'            => $this->supplier->id,
                'supplier_code' => $this->supplier->supplier_code,
                'name'          => $this->supplier->supplier_name,
            ] : null),
            'grn' => $this->whenLoaded('grn', fn () => $this->grn ? [
                'id'           => $this->grn->id,
                'grn_no'       => $this->grn->grn_no,
                'grn_date'     => $this->grn->grn_date?->toDateString(),
                'reference_no' => $this->grn->reference_no,
                'total_amount' => $this->grn->total_amount !== null ? (float) $this->grn->total_amount : null,
            ] : null),
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

            'items' => $this->whenLoaded('items', fn ($items) => SupplierReturnItemResource::collection($items)),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
