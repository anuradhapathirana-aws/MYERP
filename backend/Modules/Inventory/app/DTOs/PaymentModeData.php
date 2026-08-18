<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\StorePaymentModeRequest;
use Modules\Inventory\Http\Requests\UpdatePaymentModeRequest;

final class PaymentModeData
{
    public function __construct(
        public readonly string $payment_mode_name,
        public readonly string $code,
        public readonly bool   $requires_bank_details,
        public readonly bool   $requires_reference_no,
        public readonly bool   $requires_date,
        public readonly int    $sort_order,
        public readonly bool   $is_active,
    ) {}

    public static function fromStoreRequest(StorePaymentModeRequest $request): self
    {
        return new self(
            payment_mode_name:     trim($request->input('payment_mode_name')),
            code:                  trim($request->input('code')),
            requires_bank_details: (bool) $request->input('requires_bank_details', false),
            requires_reference_no: (bool) $request->input('requires_reference_no', false),
            requires_date:         (bool) $request->input('requires_date', false),
            sort_order:            (int) $request->input('sort_order', 0),
            is_active:             (bool) $request->input('is_active', true),
        );
    }

    public static function fromUpdateRequest(UpdatePaymentModeRequest $request): self
    {
        return new self(
            payment_mode_name:     trim($request->input('payment_mode_name')),
            code:                  trim($request->input('code')),
            requires_bank_details: (bool) $request->input('requires_bank_details', false),
            requires_reference_no: (bool) $request->input('requires_reference_no', false),
            requires_date:         (bool) $request->input('requires_date', false),
            sort_order:            (int) $request->input('sort_order', 0),
            is_active:             (bool) $request->input('is_active', true),
        );
    }
}
