<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesOrderPieceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'piece_id'       => $this->piece_id,
            'piece_code'     => $this->piece_code,
            // weight = what the roll holds; taken_quantity = the slice this line sells.
            // They differ when the roll is cut. Both in the product's stocking UOM.
            'weight'         => (float) $this->weight,
            'taken_quantity' => (float) $this->taken_quantity,
            'is_cut'         => (float) $this->taken_quantity < (float) $this->weight - 0.000001,
            'grn_unit_price' => (float) $this->grn_unit_price,
            'roll_no'        => $this->whenLoaded('piece', fn () => $this->piece?->roll_no),
            'delivered'      => $this->whenLoaded('piece', fn () => $this->piece?->status === \Modules\Inventory\Models\GrnItemPiece::STATUS_DELIVERED),
        ];
    }
}
