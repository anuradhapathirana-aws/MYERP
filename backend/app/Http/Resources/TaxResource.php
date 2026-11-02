<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Tax */
class TaxResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'code'           => $this->code,
            'name'           => $this->name,
            'tax_type'       => $this->tax_type?->value,
            'tax_type_label' => $this->tax_type?->label(),
            'rate'           => (float) $this->rate,
            'effective_from' => $this->effective_from?->format('Y-m-d'),
            'is_active'      => $this->is_active,
            'is_system'      => $this->is_system,
        ];
    }
}
