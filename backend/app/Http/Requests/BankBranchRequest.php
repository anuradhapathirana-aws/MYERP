<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BankBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $id = $this->route('bank_branch')?->id;

        return [
            'bank_id'     => ['required', 'integer', 'exists:core_banks,id'],
            'branch_name' => ['required', 'string', 'max:100'],
            'branch_code' => [
                'required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/',
                // Branch codes are unique WITHIN a bank, not globally.
                Rule::unique('core_bank_branches', 'branch_code')
                    ->ignore($id)
                    ->where(fn ($q) => $q->where('bank_id', $this->input('bank_id')))
                    ->whereNull('deleted_at'),
            ],
            'swift_code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'address'    => ['nullable', 'string', 'max:255'],
            'is_active'  => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'bank_id'     => 'bank',
            'branch_name' => 'branch name',
            'branch_code' => 'branch code',
            'swift_code'  => 'SWIFT code',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'branch_code.unique' => 'This branch code is already used by another branch of the selected bank.',
            'branch_code.regex'  => 'The branch code may only contain letters, numbers, hyphens and underscores.',
            'swift_code.regex'   => 'The SWIFT code may only contain letters and numbers.',
        ];
    }
}
