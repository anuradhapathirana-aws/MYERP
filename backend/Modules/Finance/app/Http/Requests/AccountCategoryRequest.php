<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Enums\AccountType;

/**
 * `code` is never accepted from input — it is generated server-side and is
 * immutable. Anything the client sends under that key is ignored.
 */
class AccountCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $id = $this->route('account_category')?->id;

        return [
            'account_type'  => ['required', 'string', Rule::in(AccountType::values())],
            'category_name' => [
                'required', 'string', 'max:100',
                // A category name is unique WITHIN its account type: "Current"
                // may legitimately exist under both Assets and Liabilities.
                Rule::unique('fin_account_categories', 'category_name')
                    ->ignore($id)
                    ->where(fn ($q) => $q->where('account_type', $this->input('account_type')))
                    ->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active'   => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'account_type'  => 'account type',
            'category_name' => 'account category',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'category_name.unique' => 'An account category with this name already exists under the selected account type.',
            'account_type.in'      => 'Select a valid account type.',
        ];
    }
}
