<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Support\Quantity;

class GoodsReceivedNoteItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'grn_id'            => $this->grn_id,
            'po_item_id'        => $this->po_item_id,
            'product_id'        => $this->product_id,
            'unit_id'           => $this->unit_id,
            'attribute_id'      => $this->attribute_id,
            'quantity_ordered'  => (float) $this->quantity_ordered,
            'quantity_received' => (float) $this->quantity_received,
            'conversion_factor' => (float) $this->conversion_factor,
            'base_quantity'     => (float) $this->base_quantity,
            'no_of_pieces'      => (int) $this->no_of_pieces,
            'unit_price'        => (float) $this->unit_price,
            'discount'          => (float) $this->discount,
            'tax'               => (float) $this->tax,
            'line_total'        => (float) $this->line_total,
            'batch_no'          => $this->batch_no,
            'expiry_date'       => $this->expiry_date?->toDateString(),

            'product' => $this->whenLoaded('product', fn () => [
                'id'           => $this->product->id,
                'name'         => $this->product->name,
                'product_code' => $this->product->product_code,
                'is_batch'     => $this->product->is_batch,
                'is_serial'    => $this->product->is_serial,
                // Scopes the line's UOM dropdown to units convertible to the stocking UOM
                'base_unit_type_id'     => $this->product->base_unit_type_id,
                'base_unit_category_id' => $this->product->baseUnit?->unit_category_id,
            ]),
            'unit' => $this->whenLoaded('unit', fn () => [
                'id'   => $this->unit->id,
                'name' => $this->unit->name,
            ]),
            'attribute' => $this->whenLoaded('attribute', fn () => $this->attribute ? [
                'id'   => $this->attribute->id,
                'name' => $this->attribute->attribute_name,
            ] : null),
            'batch_assignments' => $this->whenLoaded('batchAssignments', fn () =>
                $this->batchAssignments->map(fn ($a) => [
                    'id'                 => $a->id,
                    'batch_id'           => $a->batch_id,
                    'quantity'           => (float) $a->quantity,
                    'unit_cost'          => (float) $a->unit_cost,
                    'batch_no'           => $a->batch?->batch_no,
                    'supplier_batch_no'  => $a->batch?->supplier_batch_no,
                    'mfg_date'           => $a->batch?->mfg_date?->toDateString(),
                    'expiry_date'        => $a->batch?->expiry_date?->toDateString(),
                    'status'             => $a->batch?->status?->value,
                    'status_label'       => $a->batch?->status?->label(),
                    'notes'              => $a->batch?->notes,
                ])
            ),
            // The rolls this line was RECEIVED as. Offcuts created later by partial sales
            // carry a parent_piece_id and are excluded — they are stock events after the
            // fact, not part of the receipt, and counting them would break the roll
            // editor's Σ rolls = Qty Received balance.
            //
            // Weights are stored in the product's stocking UOM (see GoodsReceivedNoteService)
            // and handed back in the line's own UOM, which is what the rest of this payload
            // — quantity_received, unit_price, line_total — is denominated in.
            'pieces' => $this->whenLoaded('pieces', fn () =>
                $this->pieces
                    ->whereNull('parent_piece_id')
                    ->sortBy('piece_no')
                    ->values()
                    ->map(fn ($p) => [
                        'id'       => $p->id,
                        'piece_no' => $p->piece_no,
                        'roll_no'  => $p->roll_no,
                        'weight'   => $p->weight !== null ? $this->inLineUom((float) $p->weight) : null,
                    ])
            ),
        ];
    }

    /** Re-expresses a stocking-UOM quantity in the unit this line was received in. */
    private function inLineUom(float $baseQuantity): float
    {
        $factor = (float) $this->conversion_factor;

        return $factor > 0 ? Quantity::round($baseQuantity / $factor) : $baseQuantity;
    }
}
