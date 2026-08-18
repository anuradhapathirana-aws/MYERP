<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;
        $isStore = $this->isMethod('POST');

        return [
            'name'             => ['required', 'string', 'max:100'],
            'email'            => ['required', 'email', 'max:100', Rule::unique('users', 'email')->ignore($userId)],
            'roles'            => ['required', 'array', 'min:1'],
            'roles.*'          => ['string', Rule::exists('roles', 'name')],
            'active_modules'   => ['nullable', 'array'],
            'active_modules.*' => ['string'],
            'password'         => [
                $isStore ? 'required' : 'nullable',
                'string',
                Password::min(8)->letters()->numbers(),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'roles.*'          => 'role',
            'active_modules'   => 'active modules',
            'active_modules.*' => 'module',
        ];
    }
}
