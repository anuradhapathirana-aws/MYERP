<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\UnitType;

class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var \Modules\Inventory\Models\Product $this */
        return [
            'id'           => $this->id,

            // Identifiers
            'product_code' => $this->product_code,
            'reference_no' => $this->reference_no,
            'ean_13'       => $this->ean_13,

            // Core
            'name'         => $this->name,
            'display_name' => $this->display_name,
            'product_type' => $this->product_type,
            'description'  => $this->description,

            // Classification
            'category_id'   => $this->category_id,
            'category_name' => $this->whenLoaded('category', fn () => $this->category?->category_name),
            'location_id'   => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->location_name),

            // Stocking (base) UOM — drives every stock balance. The category is what
            // GRN and SO forms filter their UOM dropdowns by; has_stock locks the field.
            'base_unit_type_id'     => $this->base_unit_type_id,
            'base_unit_symbol'      => $this->baseUnit?->symbol ?? $this->baseUnit?->name,
            'base_unit_category_id' => $this->baseUnit?->unit_category_id,
            'has_stock'             => $this->hasStockMovements(),

            // Reorder
            'reorder_level'  => $this->reorder_level !== null ? (float) $this->reorder_level : null,
            'reorder_qty'    => $this->reorder_qty   !== null ? (float) $this->reorder_qty   : null,
            'reorder_period' => $this->reorder_period,

            // Stock control
            'stock_releasing_method' => $this->stock_releasing_method,
            'tracking_type'          => $this->tracking_type,

            // Flags
            'lock_purchase'             => $this->lock_purchase,
            'allow_complimentary_items' => $this->allow_complimentary_items,
            'free_issue'                => $this->free_issue,
            'allow_minus'               => $this->allow_minus,
            'not_allow_direct_sale'     => $this->not_allow_direct_sale,
            'non_returnable'            => $this->non_returnable,
            'is_empty'                  => $this->is_empty,
            'service_charge'            => $this->service_charge,
            'loyalty'                   => $this->loyalty,
            'is_batch'                  => $this->is_batch,
            'is_serial'                 => $this->is_serial,

            // Location & Store pairs
            'location_stores' => $this->whenLoaded('locationStores', fn () => $this->locationStores
                ->map(fn ($ls) => [
                    'location_id' => $ls->location_id,
                    'store_id'    => $ls->store_id,
                ])
                ->values()
                ->all()
            ),

            // Relationships (included only when loaded — avoids N+1)
            'product_attributes' => $this->whenLoaded('productAttributes', fn () => $this->productAttributes
                ->map(fn ($pa) => [
                    'attribute_type_id' => $pa->attribute_type_id,
                    'attribute_id'      => $pa->attribute_id,
                ])
                ->values()
                ->all()
            ),

            'suppliers' => $this->whenLoaded('suppliers', fn () => $this->suppliers
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->supplier_name])
                ->values()
                ->all()
            ),

            'cost_details' => $this->whenLoaded('salesChannels', function () {
                $unitTypes = UnitType::all(['id', 'unit_category_id'])->keyBy('id');

                return $this->salesChannels
                    ->map(fn ($c) => [
                        'sales_channel_id'               => $c->id,
                        'sales_channel_name'             => $c->sales_channel_name,
                        'unit_type_id'                   => $c->pivot->unit_type_id,
                        'unit_category_id'               => $c->pivot->unit_type_id
                            ? $unitTypes->get($c->pivot->unit_type_id)?->unit_category_id
                            : null,
                        'num_of_units'                   => $c->pivot->num_of_units                   !== null ? (float) $c->pivot->num_of_units                   : null,
                        'cost_price'                     => $c->pivot->cost_price                     !== null ? (float) $c->pivot->cost_price                     : null,
                        'margin'                         => $c->pivot->margin                         !== null ? (float) $c->pivot->margin                         : null,
                        'margin_type'                    => $c->pivot->margin_type                    ?? 'percentage',
                        'selling_price'                  => $c->pivot->selling_price                  !== null ? (float) $c->pivot->selling_price                  : null,
                        'max_price'                      => $c->pivot->max_price                      !== null ? (float) $c->pivot->max_price                      : null,
                        'min_price'                      => $c->pivot->min_price                      !== null ? (float) $c->pivot->min_price                      : null,
                        'wholesale_price'                => $c->pivot->wholesale_price                !== null ? (float) $c->pivot->wholesale_price                : null,
                        'sale_privileges_discount'       => $c->pivot->sale_privileges_discount       !== null ? (float) $c->pivot->sale_privileges_discount       : null,
                        'purchasing_privileges_discount' => $c->pivot->purchasing_privileges_discount !== null ? (float) $c->pivot->purchasing_privileges_discount : null,
                    ])
                    ->values()
                    ->all();
            }),

            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
