<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Finance\Enums\AccountType;
use Modules\Finance\Services\AccountTransactionsService;

/**
 * Ledger Details: the shared ledger filters plus WHICH accounts to report on.
 * The account criteria are turned into a LedgerSelection by
 * LedgerSelection::fromRequest(); this class only validates them.
 */
class LedgerDetailsRequest extends LedgerFilterRequest
{
    /** Hand-picked accounts per request; past this, select by group or range. */
    public const MAX_PICKED_ACCOUNTS = 500;

    /** @return array<string, mixed> */
    protected function extraRules(): array
    {
        return [
            'account_ids'         => ['nullable', 'array', 'max:' . self::MAX_PICKED_ACCOUNTS],
            'account_ids.*'       => ['integer', 'min:1', 'distinct'],
            'code_from'           => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'code_to'             => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'control_account_id'  => ['nullable', 'integer', 'min:1'],
            'account_category_id' => ['nullable', 'integer', 'min:1'],
            'account_type'        => ['nullable', Rule::enum(AccountType::class)],
            'include_parties'     => ['nullable', 'boolean'],
            'include_no_activity' => ['nullable', 'boolean'],
            'view'                => ['nullable', Rule::in(AccountTransactionsService::VIEWS)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'account_ids.max'    => 'Pick at most ' . self::MAX_PICKED_ACCOUNTS . ' accounts, or select by Control Account, Category or code range.',
            'code_from.regex'    => 'Account codes contain only letters, digits, dots and dashes.',
            'code_to.regex'      => 'Account codes contain only letters, digits, dots and dashes.',
        ];
    }

    /** The printed layout: 'list' (default) or 't_account'. */
    public function view(): string
    {
        return (string) ($this->validated()['view'] ?? AccountTransactionsService::VIEW_LIST);
    }
}
