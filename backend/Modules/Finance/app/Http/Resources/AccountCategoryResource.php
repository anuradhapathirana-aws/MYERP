<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Modules\Finance\Models\AccountCategory */
class AccountCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'code'                   => $this->code,
            'account_type'           => $this->account_type->value,
            'account_type_label'     => $this->account_type->label(),
            'normal_balance'         => $this->account_type->normalBalance(),
            'category_name'          => $this->category_name,
            // Own section (null = not classified) and where it actually reports.
            'pl_section'             => $this->pl_section?->value,
            'pl_section_label'       => $this->effectivePlSection()?->label(),
            'description'            => $this->description,
            'is_active'              => $this->is_active,
            'control_accounts_count' => $this->whenCounted('controlAccounts'),
        ];
    }
}
