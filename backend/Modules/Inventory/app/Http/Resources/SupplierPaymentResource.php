<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierPaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'payment_no'       => $this->payment_no,
            'payment_date'     => $this->payment_date?->toDateString(),
            'transaction_date' => $this->transaction_date?->toDateString(),
            'reference_no'     => $this->reference_no,
            'supplier_type'    => $this->supplier_type,
            'supplier_id'      => $this->supplier_id,
            'payment_remark'   => $this->payment_remark,
            'is_advance'       => (bool) $this->is_advance,
            'advance_amount'   => (float) $this->advance_amount,
            'gross_amount'     => (float) $this->gross_amount,
            'discount_amount'  => (float) $this->discount_amount,
            'setoff_amount'    => (float) $this->setoff_amount,
            'net_amount'       => (float) $this->net_amount,
            'status'           => $this->status->value,
            'status_label'     => $this->status->label(),
            'confirmed_at'     => $this->confirmed_at?->toDateTimeString(),

            'supplier' => $this->supplier,

            'allocations' => $this->whenLoaded('allocations', fn ($allocations) => SupplierPaymentAllocationResource::collection($allocations)),
            'setoffs'     => $this->whenLoaded('setoffs', fn ($setoffs) => SupplierPaymentSetoffResource::collection($setoffs)),
            'settlements' => $this->whenLoaded('settlements', fn ($settlements) => SupplierPaymentSettlementResource::collection($settlements)),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
