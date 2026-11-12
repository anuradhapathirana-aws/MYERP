<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Services\BankReconciliationService;

/**
 * A bank-only entry found on the statement and not yet in the books: bank
 * charges (money out) or interest (money in). The other side is always the
 * account mapped to that role -- the user picks the type, never an account.
 * BankReconciliationService checks the bank account under the user's companies.
 */
class BankEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = static fn ($v) => is_string($v) ? trim(strip_tags($v)) : $v;

        $this->merge(array_filter([
            'description' => $clean($this->input('description')),
            'reference'   => $clean($this->input('reference')),
        ], static fn ($v) => $v !== null));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'request_id'      => ['nullable', 'uuid'],
            'bank_account_id' => ['required', 'integer'],
            'entry_date'      => ['required', 'date_format:Y-m-d', 'before_or_equal:' . CarbonImmutable::today()->toDateString()],
            'type'            => ['required', Rule::in(array_keys(BankReconciliationService::ENTRY_TYPES))],
            'amount'          => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:' . BillRequest::MAX_AMOUNT],
            'description'     => ['required', 'string', 'min:3', 'max:200'],
            'reference'       => ['nullable', 'string', 'max:30'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'bank_account_id' => 'bank account',
            'entry_date'      => 'date',
            'type'            => 'entry type',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'entry_date.before_or_equal' => 'The date cannot be in the future.',
            'amount.gt'                  => 'Enter an amount above zero.',
            'amount.decimal'             => 'The amount can have at most 2 decimal places.',
            'type.in'                    => 'Choose Bank Charges or Interest Income.',
        ];
    }
}
