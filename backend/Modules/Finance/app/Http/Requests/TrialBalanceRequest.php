<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Requests;

/**
 * Trial Balance: the shared ledger filters plus the Standard / Detailed
 * (B/F & C/F) switch. Before this request existed the filters went to the
 * service unvalidated and without company access.
 */
class TrialBalanceRequest extends LedgerFilterRequest
{
    /** @return array<string, mixed> */
    protected function extraRules(): array
    {
        return [
            'detailed' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed> $validated
     * @return array<string, mixed>
     */
    protected function extraFilters(array $validated): array
    {
        return ['detailed' => (bool) ($validated['detailed'] ?? false)];
    }
}
