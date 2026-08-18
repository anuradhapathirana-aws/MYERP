<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GrnItemBatchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'grn_item_id' => $this->grn_item_id,
            'batch_id'    => $this->batch_id,
            'quantity'    => (float) $this->quantity,
            'unit_cost'   => (float) $this->unit_cost,

            'batch' => $this->whenLoaded('batch', fn () => new BatchResource($this->batch)),
        ];
    }
}
