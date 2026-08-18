<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesOrderItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'so_id'        => $this->so_id,
            'product_id'   => $this->product_id,
            'unit_id'      => $this->unit_id,
            'price_unit_id' => $this->price_unit_id,
            'attribute_id' => $this->attribute_id,
            'is_scanned'   => $this->is_scanned,
            'quantity'     => (float) $this->quantity,
            'quantity_delivered' => (float) $this->quantity_delivered,
            'conversion_factor'  => (float) $this->conversion_factor,
            'base_quantity'      => (float) $this->base_quantity,
            'unit_price'   => (float) $this->unit_price,
            'discount'     => (float) $this->discount,
            'tax'          => (float) $this->tax,
            'line_total'   => (float) $this->line_total,
            'remarks'      => $this->remarks,

            'product' => $this->whenLoaded('product', fn () => [
                'id'           => $this->product->id,
                'name'         => $this->product->name,
                'product_code' => $this->product->product_code,
                // Exempts the product from the plain-stock check — allow_minus is the
                // deliberate way to let a product be sold/delivered below zero stock.
                'allow_minus'  => $this->product->allow_minus,
                // Scopes the line's UOM dropdown to units convertible to the stocking UOM
                'base_unit_type_id'     => $this->product->base_unit_type_id,
                'base_unit_category_id' => $this->product->baseUnit?->unit_category_id,
            ]),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit ? [
                'id'   => $this->unit->id,
                'name' => $this->unit->name,
            ] : null),
            // The unit the price is quoted per (may differ from the quantity's unit)
            'price_unit' => $this->whenLoaded('priceUnit', fn () => $this->priceUnit ? [
                'id'     => $this->priceUnit->id,
                'name'   => $this->priceUnit->name,
                'symbol' => $this->priceUnit->symbol,
            ] : null),
            'attribute' => $this->whenLoaded('attribute', fn () => $this->attribute ? [
                'id'   => $this->attribute->id,
                'name' => $this->attribute->attribute_name,
            ] : null),

            'pieces' => $this->when(
                $this->resource->relationLoaded('pieces'),
                fn () => SalesOrderPieceResource::collection($this->pieces),
            ),
        ];
    }
}
