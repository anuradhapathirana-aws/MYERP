<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\BankBranch */
class BankBranchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'bank_id'     => $this->bank_id,
            // Eager-loaded by the service; never triggers a lazy query here.
            'bank_name'   => $this->whenLoaded('bank', fn () => $this->bank?->bank_name),
            'bank_code'   => $this->whenLoaded('bank', fn () => $this->bank?->bank_code),
            'branch_name' => $this->branch_name,
            'branch_code' => $this->branch_code,
            'swift_code'  => $this->swift_code,
            'address'     => $this->address,
            'is_active'   => $this->is_active,
        ];
    }
}
