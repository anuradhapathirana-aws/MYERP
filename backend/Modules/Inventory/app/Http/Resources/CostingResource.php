<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CostingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'document_no'   => $this->document_no,
            'reference_no'  => $this->reference_no,
            'supplier_id'   => $this->supplier_id,
            'costing_type'  => $this->costing_type->value,
            'costing_type_label' => $this->costing_type->label(),
            'costing_type_short' => $this->costing_type->shortLabel(),

            'total_items'      => (float) $this->total_items,
            'material_cost'    => (float) $this->material_cost,
            'bill_of_lading'   => $this->bill_of_lading,
            'expected_date'    => $this->expected_date?->toDateString(),
            'transaction_date' => $this->transaction_date?->toDateString(),
            'note'             => $this->note,

            // Pricing controls
            'default_margin_pct' => (float) $this->default_margin_pct,
            'apply_sscl'         => (bool) $this->apply_sscl,
            'apply_vat'          => (bool) $this->apply_vat,

            // Summary fields
            'total_additional_expenses' => (float) $this->total_additional_expenses,
            'raw_material_cost'         => (float) $this->raw_material_cost,
            'total_landed_cost'         => (float) $this->total_landed_cost,
            'value_addition_pct'        => (float) $this->value_addition_pct,
            'value_addition_amount'     => (float) $this->value_addition_amount,
            'fob_cif_cost'              => (float) $this->fob_cif_cost,
            'sscl_pct'                  => (float) $this->sscl_pct,
            'sscl_amount'               => (float) $this->sscl_amount,
            'gross_fob_cif_value'       => (float) $this->gross_fob_cif_value,
            'vat_pct'                   => (float) $this->vat_pct,
            'vat_amount'                => (float) $this->vat_amount,
            'total_price_with_vat'      => (float) $this->total_price_with_vat,

            'status'       => $this->status->value,
            'status_label' => $this->status->label(),
            'confirmed_at' => $this->confirmed_at?->toDateTimeString(),
            'created_at'   => $this->created_at?->toDateTimeString(),
            'updated_at'   => $this->updated_at?->toDateTimeString(),

            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id'            => $this->supplier->id,
                'name'          => $this->supplier->supplier_name ?? '',
                'supplier_code' => $this->supplier->supplier_code ?? '',
            ]),

            'costing_grns' => $this->whenLoaded('costingGrns', fn () => $this->costingGrns->map(fn ($cg) => [
                'id'        => $cg->id,
                'grn_id'    => $cg->grn_id,
                'grn_total' => (float) $cg->grn_total,
                'grn'       => $cg->relationLoaded('grn') && $cg->grn ? [
                    'id'           => $cg->grn->id,
                    'grn_no'       => $cg->grn->grn_no,
                    'grn_date'     => $cg->grn->grn_date?->toDateString(),
                    'total_amount' => (float) $cg->grn->total_amount,
                    'total_items'  => $cg->grn->relationLoaded('items')
                        ? $cg->grn->items->sum('quantity_received')
                        : null,
                ] : null,
            ])),

            'items' => CostingItemResource::collection($this->whenLoaded('items')),

            'expenses' => $this->whenLoaded('expenses', fn () => $this->expenses->map(fn ($e) => [
                'id'              => $e->id,
                'expense_type_id' => $e->expense_type_id,
                'amount'          => (float) $e->amount,
                'note'            => $e->note,
                'expense_type'    => $e->relationLoaded('expenseType') && $e->expenseType ? [
                    'id'   => $e->expenseType->id,
                    'name' => $e->expenseType->name,
                ] : null,
            ])),
        ];
    }
}
