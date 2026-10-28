<?php

declare(strict_types=1);

namespace Modules\Finance\DTOs;

use Modules\Finance\Http\Requests\BillPaymentRequest;

/**
 * A bill payment on its way to BillPaymentService, built from validated()
 * input only. No payment_no, status or total: the service owns those.
 */
final class BillPaymentData
{
    /** @param list<array{bill_id:int, amount:string}> $allocations */
    public function __construct(
        public readonly ?string       $request_id,
        public readonly int           $supplier_id,
        public readonly int           $company_id,
        public readonly string        $payment_date,
        public readonly ?string       $reference_no,
        public readonly int           $pay_from_account_id,
        public readonly int           $payment_mode_id,
        public readonly ?string       $cheque_no,
        public readonly ?string       $cheque_date,
        public readonly ?string       $description,
        public readonly array         $allocations,
        public readonly ?string       $updated_at,
    ) {}

    public static function fromRequest(BillPaymentRequest $request): self
    {
        $v = $request->validated();

        $allocations = [];

        foreach ($v['allocations'] as $row) {
            $allocations[] = [
                'bill_id' => (int) $row['bill_id'],
                // Kept as a 2-dp string; money never travels as a float.
                'amount'  => number_format((float) $row['amount'], 2, '.', ''),
            ];
        }

        $reference = $v['reference_no'] ?? null;

        return new self(
            request_id:          $v['request_id'] ?? null,
            supplier_id:         (int) $v['supplier_id'],
            company_id:          (int) $v['company_id'],
            payment_date:        $v['payment_date'],
            reference_no:        is_string($reference) && $reference !== '' ? $reference : null,
            pay_from_account_id: (int) $v['pay_from_account_id'],
            payment_mode_id:     (int) $v['payment_mode_id'],
            cheque_no:           $v['cheque_no'] ?? null,
            cheque_date:         $v['cheque_date'] ?? null,
            description:         isset($v['description']) && $v['description'] !== '' ? $v['description'] : null,
            allocations:         $allocations,
            updated_at:          $v['updated_at'] ?? null,
        );
    }
}
