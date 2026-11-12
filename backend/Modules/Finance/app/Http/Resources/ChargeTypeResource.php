<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Finance\Models\ChargeType;

/** @mixin ChargeType */
class ChargeTypeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ChargeType $type */
        $type = $this->resource;

        $account = $type->relationLoaded('ledgerAccount') ? $type->ledgerAccount : null;

        return [
            'id'                  => $type->id,
            'code'                => $type->code,
            'name'                => $type->name,
            'treatment'           => $type->treatment?->value,
            'treatment_label'     => $type->treatment?->label(),
            'ledger_account_id'   => $type->ledger_account_id,
            'ledger_account_name' => $account === null ? null : $account->code . ' - ' . $account->ledger_account_name,
            'fob_expense_type_id' => $type->fob_expense_type_id,
            'cif_expense_type_id' => $type->cif_expense_type_id,
            'is_active'           => (bool) $type->is_active,
            'is_system'           => (bool) $type->is_system,
            'sort_order'          => (int) $type->sort_order,
        ];
    }
}
