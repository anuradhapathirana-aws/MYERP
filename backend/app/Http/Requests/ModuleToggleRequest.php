<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ModuleToggleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is already behind the super_admin middleware.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'enabled.required' => 'Choose whether the module is on or off.',
            'enabled.boolean'  => 'The module can only be switched on or off.',
        ];
    }
}
