<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $id = $this->route('employee')?->id;

        return [
            'employee_code' => [
                'required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('core_employees', 'employee_code')->ignore($id)->whereNull('deleted_at'),
            ],
            'employee_name' => ['required', 'string', 'max:100'],
            'designation'   => ['nullable', 'string', 'max:100'],
            'department'    => ['nullable', 'string', 'max:100'],
            'location_id'   => ['nullable', 'integer', 'exists:inv_locations,id'],
            'mobile'        => ['nullable', 'string', 'max:20'],
            'email'         => ['nullable', 'email', 'max:100'],
            'is_active'     => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'employee_code' => 'employee code',
            'employee_name' => 'employee name',
            'location_id'   => 'location',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'employee_code.regex' => 'The employee code may only contain letters, numbers, hyphens and underscores.',
        ];
    }
}
