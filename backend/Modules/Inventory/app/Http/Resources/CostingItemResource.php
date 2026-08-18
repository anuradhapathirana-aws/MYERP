<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Per-product landed-cost breakdown line — full per-unit price build-up
 * (purchase → +charges → landed → +margin → ±SSCL/±VAT → selling).
 *
 * Unsuffixed money is per the GRN's RECEIVING unit (unit_symbol); *_base is the
 * same money per the product's stocking UOM (base_unit_symbol), which is what the
 * customer is invoiced in. The two differ only when conversion_factor ≠ 1.
 */
class CostingItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'grn_id'                => $this->grn_id,
            'grn_item_id'           => $this->grn_item_id,
            'product_id'            => $this->product_id,
            'attribute_id'          => $this->attribute_id,
            'unit_id'               => $this->unit_id,
            'base_unit_id'          => $this->base_unit_id,
            'quantity'              => (float) $this->quantity,
            'conversion_factor'     => (float) $this->conversion_factor ?: 1.0,
            'base_quantity'         => (float) $this->base_quantity,
            'unit_price'            => (float) $this->unit_price,
            'charge_portion'        => (float) $this->charge_portion,
            'landed_unit_cost'      => (float) $this->landed_unit_cost,
            'landed_unit_cost_base' => (float) $this->landed_unit_cost_base,
            'expense_total_base'    => (float) $this->expense_total_base,
            'margin_pct'            => $this->margin_pct !== null ? (float) $this->margin_pct : null,
            'margin_amount'         => (float) $this->margin_amount,
            'margin_amount_base'    => (float) $this->margin_amount_base,
            'sscl_pct'              => $this->sscl_pct !== null ? (float) $this->sscl_pct : null,
            'sscl_amount'           => (float) $this->sscl_amount,
            'sscl_amount_base'      => (float) $this->sscl_amount_base,
            'vat_pct'               => $this->vat_pct !== null ? (float) $this->vat_pct : null,
            'vat_amount'            => (float) $this->vat_amount,
            'vat_amount_base'       => (float) $this->vat_amount_base,
            'before_tax_price'      => (float) $this->before_tax_price,
            'before_tax_price_base' => (float) $this->before_tax_price_base,
            'selling_price'         => (float) $this->selling_price,
            'selling_price_base'    => (float) $this->selling_price_base,
            'is_price_overridden'   => (bool) $this->is_price_overridden,

            // Typed per-base-unit expense amounts, one per expense type
            'expenses' => $this->whenLoaded('expenses', fn () => $this->expenses->map(fn ($e) => [
                'expense_type_id' => $e->expense_type_id,
                'amount'          => (float) $e->amount,
                'name'            => $e->relationLoaded('expenseType') ? $e->expenseType?->name : null,
            ])),

            'product_name'       => $this->whenLoaded('product', fn () => $this->product?->name),
            'product_code'       => $this->whenLoaded('product', fn () => $this->product?->product_code),
            'color'              => $this->whenLoaded('attribute', fn () => $this->attribute?->attribute_name),
            'grn_no'             => $this->whenLoaded('grn', fn () => $this->grn?->grn_no),
            'unit_symbol'        => $this->whenLoaded('unit', fn () => $this->unit?->symbol),
            'unit_position'      => $this->whenLoaded('unit', fn () => $this->unit?->unit_position),
            'base_unit_symbol'   => $this->whenLoaded('baseUnit', fn () => $this->baseUnit?->symbol),
            'base_unit_position' => $this->whenLoaded('baseUnit', fn () => $this->baseUnit?->unit_position),
        ];
    }
}
