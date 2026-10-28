<?php

declare(strict_types=1);

namespace Modules\Finance\DTOs;

use Modules\Finance\Http\Requests\BillRequest;

/**
 * A bill on its way to BillService, built from validated() input only.
 *
 * No bill_no, status or amounts paid: the service owns those, so they cannot
 * arrive from a request however it is crafted.
 */
final class BillData
{
    /** @param list<array{ledger_account_id:int, amount:string, description:?string}> $lines */
    public function __construct(
        public readonly ?string $request_id,
        public readonly int     $supplier_id,
        public readonly string  $transaction_date,
        public readonly string  $document_no,
        public readonly ?string $document_date,
        public readonly string  $due_date,
        public readonly int     $company_id,
        public readonly ?int    $location_id,
        public readonly ?string $description,
        public readonly array   $lines,
        public readonly ?string $updated_at,
    ) {}

    public static function fromRequest(BillRequest $request): self
    {
        $v = $request->validated();

        $lines = [];

        foreach ($v['lines'] as $line) {
            $description = $line['description'] ?? null;

            $lines[] = [
                'ledger_account_id' => (int) $line['ledger_account_id'],
                // Kept as a 2-dp string; money never travels as a float.
                'amount'            => number_format((float) $line['amount'], 2, '.', ''),
                'description'       => is_string($description) && $description !== '' ? $description : null,
            ];
        }

        return new self(
            request_id:       $v['request_id'] ?? null,
            supplier_id:      (int) $v['supplier_id'],
            transaction_date: $v['transaction_date'],
            document_no:      $v['document_no'],
            document_date:    $v['document_date'] ?? null,
            due_date:         $v['due_date'],
            company_id:       (int) $v['company_id'],
            location_id:      isset($v['location_id']) ? (int) $v['location_id'] : null,
            description:      isset($v['description']) && $v['description'] !== '' ? $v['description'] : null,
            lines:            $lines,
            updated_at:       $v['updated_at'] ?? null,
        );
    }
}
