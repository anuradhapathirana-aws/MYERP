<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Bank */
class BankResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'bank_name'      => $this->bank_name,
            'bank_code'      => $this->bank_code,
            'address'        => $this->address,
            'is_active'      => $this->is_active,
            'branches_count' => $this->whenCounted('branches'),
        ];
    }
}
