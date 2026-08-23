<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Enums\StockReconciliationStatus;
use Modules\Inventory\Models\ProductLocationStore;

class StockReconciliationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'reconciliation_no'  => $this->reconciliation_no,
            'product_id'         => $this->product_id,
            'attribute_id'       => $this->attribute_id,
            'store_id'           => $this->store_id,
            'location_id'        => $this->location_id,
            'batch_id'           => $this->batch_id,
            'unit_id'            => $this->unit_id,
            'system_qty_base'    => (float) $this->system_qty_base,
            'counted_qty_base'   => (float) $this->counted_qty_base,
            'variance_qty_base'  => (float) $this->variance_qty_base,
            // Live balance for the target — lets the approver see, before confirming,
            // whether stock moved since this was drafted. Only worth computing while
            // the decision is still pending; a decided reconciliation is history.
            'live_system_qty_base' => $this->when(
                $this->status === StockReconciliationStatus::PendingApproval,
                fn () => (float) (ProductLocationStore::where('product_id', $this->product_id)
                    ->where('store_id', $this->store_id)
                    ->where('location_id', $this->location_id)
                    ->value('current_stock') ?? 0.0),
            ),
            'source_type'      => $this->source_type,
            'source_id'        => $this->source_id,
            'also_adjust_po'   => (bool) $this->also_adjust_po,
            'reason'           => $this->reason,
            'remarks'          => $this->remarks,
            'status'           => $this->status->value,
            'status_label'     => $this->status->label(),
            'submitted_at'     => $this->submitted_at?->toDateTimeString(),
            'approved_at'      => $this->approved_at?->toDateTimeString(),
            'rejection_reason' => $this->rejection_reason,

            'product' => $this->whenLoaded('product', fn () => [
                'id'           => $this->product->id,
                'name'         => $this->product->name,
                'product_code' => $this->product->product_code,
                'base_unit_symbol' => $this->product->baseUnit?->symbol,
            ]),
            'attribute' => $this->whenLoaded('attribute', fn () => $this->attribute ? [
                'id'   => $this->attribute->id,
                'name' => $this->attribute->attribute_name,
            ] : null),
            'store' => $this->whenLoaded('store', fn () => [
                'id'   => $this->store->id,
                'name' => $this->store->store_name,
            ]),
            'location' => $this->whenLoaded('location', fn () => [
                'id'   => $this->location->id,
                'name' => $this->location->name ?? $this->location->location_name ?? '',
            ]),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit ? [
                'id'     => $this->unit->id,
                'symbol' => $this->unit->symbol,
            ] : null),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
                'id'   => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null),
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy ? [
                'id'   => $this->approvedBy->id,
                'name' => $this->approvedBy->name,
            ] : null),

            'pieces' => $this->whenLoaded('pieces', fn () => StockReconciliationPieceResource::collection($this->pieces)),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
