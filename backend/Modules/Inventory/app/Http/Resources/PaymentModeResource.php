<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentModeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var \Modules\Inventory\Models\PaymentMode $this */
        return [
            'id'                     => $this->id,
            'payment_mode_name'      => $this->payment_mode_name,
            'code'                   => $this->code,
            'requires_bank_details'  => $this->requires_bank_details,
            'requires_reference_no'  => $this->requires_reference_no,
            'requires_date'          => $this->requires_date,
            'sort_order'             => $this->sort_order,
            'is_active'              => $this->is_active,
            'created_at'             => $this->created_at?->toISOString(),
            'updated_at'             => $this->updated_at?->toISOString(),
        ];
    }
}
