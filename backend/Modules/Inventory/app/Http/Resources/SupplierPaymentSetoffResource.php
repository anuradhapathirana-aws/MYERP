<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierPaymentSetoffResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'setoff_type'     => $this->setoff_type->value,
            'setoff_type_label' => $this->setoff_type->label(),
            'credit_note_id'  => $this->credit_note_id,
            'amount'          => (float) $this->amount,
            'remark'          => $this->remark,

            'credit_note' => $this->whenLoaded('creditNote', fn () => $this->creditNote ? new SupplierCreditNoteResource($this->creditNote) : null),
        ];
    }
}
