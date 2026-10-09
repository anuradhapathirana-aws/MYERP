<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Finance\Models\AccountCategory;
use Modules\Finance\Models\ControlAccount;

/** @mixin \Modules\Finance\Models\LedgerAccount */
class LedgerAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $control  = $this->whenLoaded('controlAccount');
        $hasCtrl  = $control instanceof ControlAccount;
        $category = $hasCtrl ? $control->category : null;
        $hasCat   = $category instanceof AccountCategory;

        return [
            'id'                  => $this->id,
            'code'                => $this->code,
            'ledger_account_name' => $this->ledger_account_name,
            'description'         => $this->description,
            'is_active'           => $this->is_active,

            // The full path, flattened — the list renders all four columns.
            'control_account_id'   => $this->control_account_id,
            'control_account_code' => $hasCtrl ? $control->code : null,
            'control_account_name' => $hasCtrl ? $control->control_account_name : null,
            'account_category_id'   => $hasCat ? $category->id : null,
            'account_category_name' => $hasCat ? $category->category_name : null,
            'account_type'          => $hasCat ? $category->account_type->value : null,
            'account_type_label'    => $hasCat ? $category->account_type->label() : null,
            'normal_balance'        => $hasCat ? $category->account_type->normalBalance() : null,

            // ── Cash / bank block ────────────────────────────────────────────
            'cash_book_type'       => $this->cash_book_type?->value,
            'cash_book_type_label' => $this->cash_book_type?->label(),
            'allows_cash'          => $this->allows_cash,
            'allows_cheque'        => $this->allows_cheque,

            // The owning legal entity — null on ordinary accounts, which the
            // whole group shares.
            'company_id'   => $this->company_id,
            'company_name' => $this->whenLoaded('company', fn () => $this->company?->company_name),

            'bank_id'          => $this->bank_id,
            'bank_branch_id'   => $this->bank_branch_id,
            'bank_account_no'  => $this->bank_account_no,

            // Read live from the master, never snapshotted: this is a master
            // record, so it must always show the bank's CURRENT name. Posted
            // documents are the opposite case — see the settlement tables.
            'bank_name'   => $this->whenLoaded('bank', fn () => $this->bank?->bank_name),
            'bank_code'   => $this->whenLoaded('bank', fn () => $this->bank?->bank_code),
            'branch_name' => $this->whenLoaded('bankBranch', fn () => $this->bankBranch?->branch_name),
            'branch_code' => $this->whenLoaded('bankBranch', fn () => $this->bankBranch?->branch_code),
            'swift_code'  => $this->whenLoaded('bankBranch', fn () => $this->bankBranch?->swift_code),
        ];
    }
}
