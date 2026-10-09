<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Finance\Models\ControlAccount */
class ControlAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // The list shows Account Type and Account Category on every row, so the
        // parent is flattened into the payload rather than nested — the UI
        // never needs the category object itself, only these three strings.
        $category = $this->whenLoaded('category');
        $hasType  = $category instanceof \Modules\Finance\Models\AccountCategory;

        return [
            'id'                    => $this->id,
            'code'                  => $this->code,
            'control_account_name'  => $this->control_account_name,
            'description'           => $this->description,
            'is_active'             => $this->is_active,

            'account_category_id'   => $this->account_category_id,
            'account_category_code' => $hasType ? $category->code : null,
            'account_category_name' => $hasType ? $category->category_name : null,
            'account_type'          => $hasType ? $category->account_type->value : null,
            'account_type_label'    => $hasType ? $category->account_type->label() : null,

            'ledger_accounts_count' => $this->whenCounted('ledgerAccounts'),
        ];
    }
}
