<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\StoreSupplierPaymentRequest;
use Modules\Inventory\Http\Requests\UpdateSupplierPaymentRequest;

final class SupplierPaymentData
{
    /**
     * @param array<array{reference_type:string, reference_id:int, due_date:?string, discount:?float, payment_amount:?float, line_remark:?string}> $allocations
     * @param array<array{setoff_type:string, credit_note_id:?int, amount:float, remark:?string}> $setoffs
     * @param array<array{payment_mode_id:int, amount:float, bank_name:?string, bank_account_no:?string, reference_no:?string, instrument_date:?string, is_thirdparty:?bool, remark:?string}> $settlements
     */
    public function __construct(
        public readonly string  $paymentDate,
        public readonly ?string $transactionDate,
        public readonly ?string $referenceNo,
        public readonly ?string $supplierType,
        public readonly int     $supplierId,
        public readonly ?string $paymentRemark,
        public readonly bool    $isAdvance,
        public readonly ?float  $advanceAmount,
        public readonly array   $allocations,
        public readonly array   $setoffs,
        public readonly array   $settlements,
    ) {}

    public static function fromRequest(
        StoreSupplierPaymentRequest|UpdateSupplierPaymentRequest $request,
    ): self {
        return new self(
            paymentDate:      $request->validated('payment_date'),
            transactionDate:  $request->validated('transaction_date'),
            referenceNo:      $request->validated('reference_no'),
            supplierType:     $request->validated('supplier_type'),
            supplierId:       (int) $request->validated('supplier_id'),
            paymentRemark:    $request->validated('payment_remark'),
            isAdvance:        (bool) $request->validated('is_advance'),
            advanceAmount:    $request->validated('advance_amount') !== null ? (float) $request->validated('advance_amount') : null,
            allocations:      (array) ($request->validated('allocations') ?? []),
            setoffs:          (array) ($request->validated('setoffs') ?? []),
            settlements:      (array) ($request->validated('settlements') ?? []),
        );
    }
}
