<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerReceiptAllocationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'reference_type'      => $this->reference_type,
            'reference_id'        => $this->reference_id,
            'invoice_no'          => $this->invoice_no,
            'invoice_date'        => $this->invoice_date?->toDateString(),
            'so_no'               => $this->so_no,
            'do_no'               => $this->do_no,
            'invoice_amount'      => (float) $this->invoice_amount,
            'due_date'            => $this->due_date?->toDateString(),
            'outstanding_before'  => (float) $this->outstanding_before,
            'discount'            => (float) $this->discount,
            'receipt_amount'      => (float) $this->receipt_amount,
            'line_remark'         => $this->line_remark,
        ];
    }
}
