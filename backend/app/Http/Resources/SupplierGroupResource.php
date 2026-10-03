<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SupplierGroup */
class SupplierGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                         => $this->id,
            'code'                       => $this->code,
            'name'                       => $this->name,
            'description'                => $this->description,
            'default_payable_account_id' => $this->default_payable_account_id,
            'default_expense_account_id' => $this->default_expense_account_id,
            'is_active'                  => $this->is_active,
            'sort_order'                 => $this->sort_order,
            'suppliers_count'            => $this->whenCounted('suppliers'),
        ];
    }
}
