<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $id = $this->route('supplier_group')?->id;

        return [
            'code' => [
                'required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('core_supplier_groups', 'code')->ignore($id)->whereNull('deleted_at'),
            ],
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('core_supplier_groups', 'name')->ignore($id)->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:255'],

            // Soft links to fin_ledger_accounts — validated only as integers
            // because the Finance module (and its table) may not be installed.
            'default_payable_account_id' => ['nullable', 'integer', 'min:1'],
            'default_expense_account_id' => ['nullable', 'integer', 'min:1'],

            'is_active'  => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'code'                       => 'group code',
            'name'                       => 'group name',
            'default_payable_account_id' => 'default payable account',
            'default_expense_account_id' => 'default expense account',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex' => 'The group code may only contain letters, numbers, hyphens and underscores.',
        ];
    }
}
