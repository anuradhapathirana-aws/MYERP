<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The form shows an Account Type dropdown above Account Category, but the type
 * is only there to narrow the category list — it is derived from the chosen
 * category and is NOT accepted here. Storing it would be a second source of
 * truth that can disagree with the parent.
 */
class ControlAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $id = $this->route('control_account')?->id;

        return [
            'account_category_id' => [
                'required', 'integer',
                // exists: turns a stale or tampered id into a 422 rather than
                // a 500 from the foreign key.
                Rule::exists('fin_account_categories', 'id')->whereNull('deleted_at'),
            ],
            'control_account_name' => [
                'required', 'string', 'max:100',
                Rule::unique('fin_control_accounts', 'control_account_name')
                    ->ignore($id)
                    ->where(fn ($q) => $q->where('account_category_id', $this->input('account_category_id')))
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
            'account_category_id'  => 'account category',
            'control_account_name' => 'control account',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'account_category_id.exists'   => 'The selected account category no longer exists.',
            'control_account_name.unique'  => 'A control account with this name already exists under the selected category.',
        ];
    }
}
