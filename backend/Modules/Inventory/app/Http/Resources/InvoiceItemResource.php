<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'invoice_id' => $this->invoice_id,
            'so_item_id' => $this->so_item_id,
            'do_item_id' => $this->do_item_id,
            'product_id' => $this->product_id,
            'unit_id'    => $this->unit_id,
            'price_unit_id' => $this->price_unit_id,
            'attribute_id' => $this->attribute_id,
            'quantity'   => (float) $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'discount'   => (float) $this->discount,
            'tax'        => (float) $this->tax,
            'line_total' => (float) $this->line_total,
            'remarks'    => $this->remarks,

            'product' => $this->whenLoaded('product', fn () => [
                'id'           => $this->product->id,
                'name'         => $this->product->name,
                'product_code' => $this->product->product_code,
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
        ];
    }
}
