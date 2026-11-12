<?php

declare(strict_types=1);

namespace Modules\Finance\DTOs;

use Modules\Finance\Http\Requests\BankReconciliationRequest;

/**
 * A bank reconciliation on its way to BankReconciliationService, built from
 * validated() input only. No recon_no, status or computed balances: the service
 * owns those. A line is named by its journal line id alone; amount, date and
 * cheque always come from the ledger, never the client.
 */
final class BankReconciliationData
{
    /** @param list<array{journal_line_id:int, status:string, return_date:?string}> $lines */
    public function __construct(
        public readonly ?string $request_id,
        public readonly int     $company_id,
        public readonly int     $bank_account_id,
        public readonly ?string $date_from,
        public readonly string  $statement_date,
        public readonly ?string $opening_balance,
        public readonly string  $statement_balance,
        public readonly ?string $notes,
        public readonly array   $lines,
        public readonly ?string $updated_at,
    ) {}

    public static function fromRequest(BankReconciliationRequest $request): self
    {
        $v = $request->validated();

        // Money stays a 2-dp string; it never travels as a float.
        $money = static fn ($n): string => number_format((float) ($n ?? 0), 2, '.', '');

        return new self(
            request_id:        $v['request_id'] ?? null,
            company_id:        (int) $v['company_id'],
            bank_account_id:   (int) $v['bank_account_id'],
            date_from:         $v['date_from'] ?? null,
            statement_date:    $v['statement_date'],
            // Null when not typed: the first reconciliation of an account
            // refuses that, later ones take the opening from the last posted.
            opening_balance:   isset($v['opening_balance']) && $v['opening_balance'] !== '' ? $money($v['opening_balance']) : null,
            statement_balance: $money($v['statement_balance']),
            notes:             isset($v['notes']) && $v['notes'] !== '' ? $v['notes'] : null,
            lines:             array_values(array_map(static fn (array $l): array => [
                'journal_line_id' => (int) $l['journal_line_id'],
                'status'          => (string) $l['status'],
                'return_date'     => $l['return_date'] ?? null,
            ], $v['lines'] ?? [])),
            updated_at:        $v['updated_at'] ?? null,
        );
    }
}
