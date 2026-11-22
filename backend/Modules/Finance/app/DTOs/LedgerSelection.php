<?php

declare(strict_types=1);

namespace Modules\Finance\DTOs;

use Modules\Finance\Enums\AccountType;
use Modules\Finance\Http\Requests\LedgerDetailsRequest;

/**
 * WHICH ledger accounts a ledger report covers.
 *
 * Every criterion narrows the set (they combine with AND), and an empty
 * selection means the whole chart: the full General Ledger. Adding a new way
 * to pick accounts means adding one property here and one condition in
 * LedgerDetailsService::selectedAccounts(); nothing else changes.
 */
final class LedgerSelection
{
    /**
     * @param list<int> $accountIds
     */
    public function __construct(
        public readonly array        $accountIds = [],
        public readonly ?string      $codeFrom = null,
        public readonly ?string      $codeTo = null,
        public readonly ?int         $controlAccountId = null,
        public readonly ?int         $accountCategoryId = null,
        public readonly ?AccountType $accountType = null,
        // Per-supplier / per-customer accounts (party ledger mode).
        public readonly bool         $includeParties = true,
        // Accounts with no opening balance and no movement in the period.
        public readonly bool         $includeNoActivity = false,
    ) {}

    public static function fromRequest(LedgerDetailsRequest $request): self
    {
        $v = $request->validated();

        return new self(
            accountIds:        array_values(array_map('intval', $v['account_ids'] ?? [])),
            codeFrom:          self::nullableTrim($v['code_from'] ?? null),
            codeTo:            self::nullableTrim($v['code_to'] ?? null),
            controlAccountId:  isset($v['control_account_id']) ? (int) $v['control_account_id'] : null,
            accountCategoryId: isset($v['account_category_id']) ? (int) $v['account_category_id'] : null,
            accountType:       isset($v['account_type']) ? AccountType::from($v['account_type']) : null,
            includeParties:    (bool) ($v['include_parties'] ?? true),
            includeNoActivity: (bool) ($v['include_no_activity'] ?? false),
        );
    }

    private static function nullableTrim(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
