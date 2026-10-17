<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProformaInvoiceItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $invoiced = $this->resource->getAttribute('invoiced_quantity');
        $recalled = $this->resource->getAttribute('recalled_quantity');

        return [
            'id'                => $this->id,
            'proforma_id'       => $this->proforma_id,
            'so_item_id'        => $this->so_item_id,
            'product_id'        => $this->product_id,
            'unit_id'           => $this->unit_id,
            'price_unit_id'     => $this->price_unit_id,
            'attribute_id'      => $this->attribute_id,
            'quantity'          => (float) $this->quantity,
            'invoiced_quantity' => $invoiced !== null ? (float) $invoiced : null,
            'recalled_quantity' => $recalled !== null ? (float) $recalled : null,
            // Snapshot taken when the proforma was issued (informational)
            'stock_status'      => $this->stock_status,
            'available_qty'     => $this->available_qty !== null ? (float) $this->available_qty : null,
            'unit_price'        => (float) $this->unit_price,
            'discount'          => (float) $this->discount,
            'tax'               => (float) $this->tax,
            'line_total'        => (float) $this->line_total,
            'remarks'           => $this->remarks,

            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id'           => $this->product->id,
                'name'         => $this->product->name,
                'product_code' => $this->product->product_code,
            ] : null),
            'unit' => $this->whenLoaded('unit', fn () => $this->unit ? [
                'id'   => $this->unit->id,
                'name' => $this->unit->name,
            ] : null),
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
