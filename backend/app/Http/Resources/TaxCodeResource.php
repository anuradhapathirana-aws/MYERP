<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\TaxCodeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TaxCode */
class TaxCodeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'code'               => $this->code,
            'name'               => $this->name,
            'scope'              => $this->scope?->value,
            'vat_category'       => $this->vat_category?->value,
            'vat_category_label' => $this->vat_category?->label(),
            'is_active'          => $this->is_active,
            'is_system'          => $this->is_system,
            'sort_order'         => $this->sort_order,
            // Today's rate of each tax, so the list reads "SSCL 2.5 % -> VAT 18 %".
            'components'         => app(TaxCodeService::class)->describeComponents($this->resource),
        ];
    }
}
