<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierCreditNoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'credit_note_no'     => $this->credit_note_no,
            'supplier_id'        => $this->supplier_id,
            'credit_type'        => $this->credit_type->value,
            'credit_type_label'  => $this->credit_type->label(),
            'amount'             => (float) $this->amount,
            'remaining_balance'  => (float) $this->remaining_balance,
            'remark'             => $this->remark,
            'status'             => $this->status->value,
            'status_label'       => $this->status->label(),
            'source_payment_id'  => $this->source_payment_id,
            'created_at'         => $this->created_at?->toDateTimeString(),
        ];
    }
}
