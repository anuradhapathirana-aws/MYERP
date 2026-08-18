<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerReceiptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'receipt_no'       => $this->receipt_no,
            'receipt_date'     => $this->receipt_date?->toDateString(),
            'transaction_date' => $this->transaction_date?->toDateString(),
            'reference_no'     => $this->reference_no,
            'customer_id'      => $this->customer_id,
            'receipt_remark'   => $this->receipt_remark,
            'is_advance'       => (bool) $this->is_advance,
            'advance_amount'   => (float) $this->advance_amount,
            'gross_amount'     => (float) $this->gross_amount,
            'discount_amount'  => (float) $this->discount_amount,
            'setoff_amount'    => (float) $this->setoff_amount,
            'net_amount'       => (float) $this->net_amount,
            'status'           => $this->status->value,
            'status_label'     => $this->status->label(),
            'confirmed_at'     => $this->confirmed_at?->toDateTimeString(),

            'customer' => $this->customer,

            'allocations' => $this->whenLoaded('allocations', fn ($allocations) => CustomerReceiptAllocationResource::collection($allocations)),
            'setoffs'     => $this->whenLoaded('setoffs', fn ($setoffs) => CustomerReceiptSetoffResource::collection($setoffs)),
            'settlements' => $this->whenLoaded('settlements', fn ($settlements) => CustomerReceiptSettlementResource::collection($settlements)),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
