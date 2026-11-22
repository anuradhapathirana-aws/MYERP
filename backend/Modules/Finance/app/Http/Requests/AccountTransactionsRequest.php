<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Requests;

/**
 * Filters of one account's ledger (Trial Balance drill-down, Ledger Details
 * account cards). The period, company and location come from
 * LedgerFilterRequest, so the lines agree with the row that was opened;
 * search and source_type narrow the list.
 */
class AccountTransactionsRequest extends LedgerFilterRequest
{
    /** @return array<string, mixed> */
    protected function extraRules(): array
    {
        return [
            'search'      => ['nullable', 'string', 'max:100'],
            'source_type' => ['nullable', 'string', 'max:40', 'regex:/^[a-z_]+$/'],
        ];
    }

    /**
     * @param  array<string, mixed> $validated
     * @return array<string, mixed>
     */
    protected function extraFilters(array $validated): array
    {
        return [
            'search'      => isset($validated['search']) ? trim($validated['search']) : null,
            'source_type' => $validated['source_type'] ?? null,
        ];
    }
}
