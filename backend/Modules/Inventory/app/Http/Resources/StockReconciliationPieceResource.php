<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockReconciliationPieceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'grn_item_piece_id' => $this->grn_item_piece_id,
            'old_weight'        => (float) $this->old_weight,
            'new_weight'        => (float) $this->new_weight,
            'piece_code'        => $this->whenLoaded('piece', fn () => $this->piece?->piece_code),
            'roll_no'           => $this->whenLoaded('piece', fn () => $this->piece?->roll_no),
            'current_weight'    => $this->whenLoaded('piece', fn () => $this->piece ? (float) $this->piece->weight : null),
            'current_status'    => $this->whenLoaded('piece', fn () => $this->piece?->status),
            'color'             => $this->whenLoaded('piece', fn () => $this->piece?->grnItem?->attribute?->attribute_name),
        ];
    }
}
