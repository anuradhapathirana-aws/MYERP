<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\CompanyAccessService;
use Illuminate\Contracts\Validation\Validator;
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
            // Empty or absent = every company. A restricted admin can only hand
            // out companies they can see themselves (checked in after()).
            'company_ids'      => ['nullable', 'array', 'max:200'],
            'company_ids.*'    => ['integer', 'distinct', Rule::exists('inv_companies', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $allowed = app(CompanyAccessService::class)->allowedCompanyIds($this->user());

            if ($allowed === null) {
                return;
            }

            $requested = array_map('intval', (array) $this->input('company_ids', []));

            // A restricted admin granting "all companies" (empty list) would
            // hand out more than they hold themselves.
            if ($requested === [] || array_diff($requested, $allowed) !== []) {
                $v->errors()->add('company_ids', 'You can only grant access to companies you can access yourself.');
            }
        });
    }

    public function attributes(): array
    {
        return [
            'roles.*'          => 'role',
            'active_modules'   => 'active modules',
            'active_modules.*' => 'module',
            'company_ids'      => 'companies',
            'company_ids.*'    => 'company',
        ];
    }
}
