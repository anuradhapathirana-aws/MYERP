<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierPaymentSettlementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'payment_mode_id'    => $this->payment_mode_id,
            'payment_mode_code'  => $this->payment_mode_code,
            'payment_mode_name'  => $this->payment_mode_name,
            'amount'             => (float) $this->amount,
            'bank_name'          => $this->bank_name,
            'bank_account_no'    => $this->bank_account_no,
            'reference_no'       => $this->reference_no,
            'instrument_date'    => $this->instrument_date?->toDateString(),
            'is_thirdparty'      => (bool) $this->is_thirdparty,
            'remark'             => $this->remark,
        ];
    }
}
