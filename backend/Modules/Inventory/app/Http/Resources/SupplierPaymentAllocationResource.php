<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierPaymentAllocationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'reference_type'      => $this->reference_type,
            'reference_id'        => $this->reference_id,
            'grn_no'              => $this->grn_no,
            'grn_date'            => $this->grn_date?->toDateString(),
            'po_no'               => $this->po_no,
            'reference_no'        => $this->reference_no,
            'grn_amount'          => (float) $this->grn_amount,
            'due_date'            => $this->due_date?->toDateString(),
            'outstanding_before'  => (float) $this->outstanding_before,
            'discount'            => (float) $this->discount,
            'payment_amount'      => (float) $this->payment_amount,
            'line_remark'         => $this->line_remark,
        ];
    }
}
