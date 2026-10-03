<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BankRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $id = $this->route('bank')?->id;

        return [
            'bank_name' => ['required', 'string', 'max:100'],
            'bank_code' => [
                'required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('core_banks', 'bank_code')->ignore($id)->whereNull('deleted_at'),
            ],
            'address'   => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'bank_name' => 'bank name',
            'bank_code' => 'bank code',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'bank_code.regex' => 'The bank code may only contain letters, numbers, hyphens and underscores.',
        ];
    }
}
