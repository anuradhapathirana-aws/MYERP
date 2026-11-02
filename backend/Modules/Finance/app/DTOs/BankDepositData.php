<?php

declare(strict_types=1);

namespace Modules\Finance\DTOs;

use Modules\Finance\Http\Requests\BankDepositRequest;

/**
 * A bank deposit on its way to BankDepositService, built from validated()
 * input only. No deposit_no, status or totals: the service owns those, and a
 * cheque's amount and details always come from the receipt, never the client.
 */
final class BankDepositData
{
    /** @param list<int> $cheque_ids settlement ids of the cheques being banked */
    public function __construct(
        public readonly ?string $request_id,
        public readonly int     $company_id,
        public readonly string  $deposit_date,
        public readonly int     $bank_account_id,
        public readonly ?string $slip_no,
        public readonly ?string $description,
        public readonly ?int    $cash_account_id,
        public readonly string  $cash_amount,
        public readonly array   $cheque_ids,
        public readonly ?string $updated_at,
    ) {}

    public static function fromRequest(BankDepositRequest $request): self
    {
        $v = $request->validated();

        // Kept as a 2-dp string; money never travels as a float.
        $cash = number_format((float) ($v['cash_amount'] ?? 0), 2, '.', '');

        return new self(
            request_id:      $v['request_id'] ?? null,
            company_id:      (int) $v['company_id'],
            deposit_date:    $v['deposit_date'],
            bank_account_id: (int) $v['bank_account_id'],
            slip_no:         isset($v['slip_no']) && $v['slip_no'] !== '' ? $v['slip_no'] : null,
            description:     isset($v['description']) && $v['description'] !== '' ? $v['description'] : null,
            // A cash book with nothing taken from it is not part of the deposit.
            cash_account_id: $cash !== '0.00' && ! empty($v['cash_account_id']) ? (int) $v['cash_account_id'] : null,
            cash_amount:     $cash,
            cheque_ids:      array_values(array_map('intval', $v['cheque_ids'] ?? [])),
            updated_at:      $v['updated_at'] ?? null,
        );
    }
}
