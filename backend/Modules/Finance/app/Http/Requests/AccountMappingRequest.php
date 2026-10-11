<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Enums\AccountRole;

/**
 * Changes to the role-to-account mapping.
 *
 * Shape and existence are checked here. The rule that actually matters -- that
 * the account's TYPE suits the role -- lives in AccountMappingService, because
 * it is a business rule and it needs to walk the account up to its category to
 * decide. Keeping it there also means an automatic posting resolving a role
 * gets the same guarantee as a save through this screen.
 */
class AccountMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'mappings'   => ['required', 'array', 'min:1'],
            'mappings.*' => [
                'nullable',
                'integer',
                Rule::exists('fin_ledger_accounts', 'id')->whereNull('deleted_at'),
            ],
            'company_id' => ['nullable', 'integer', Rule::exists('inv_companies', 'id')],
        ];
    }

    /**
     * Reject unknown role keys before the service sees them, so a typo in the
     * payload reads as a validation error rather than a thrown exception.
     */
    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($v): void {
            foreach (array_keys((array) $this->input('mappings', [])) as $key) {
                if (AccountRole::tryFrom((string) $key) === null) {
                    $v->errors()->add('mappings', 'Unknown posting role [' . $key . '].');
                }
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'mappings.*.exists' => 'That ledger account does not exist.',
            'mappings.required' => 'No changes were submitted.',
        ];
    }
}
