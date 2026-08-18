<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $companyId = $this->route('company')?->id;

        return [
            'company_type'   => ['required', 'string', 'max:50'],
            'company_name'   => ['required', 'string', 'max:100'],
            'registration_no'=> [
                'nullable',
                'string',
                'max:50',
                Rule::unique('inv_companies', 'registration_no')->ignore($companyId),
            ],
            'tax_reg_no'     => ['nullable', 'string', 'max:50'],
            'street_address' => ['required', 'string', 'max:100'],
            'city'           => ['nullable', 'string', 'max:50'],
            'country'        => ['nullable', 'string', 'max:50'],
            'state'          => ['nullable', 'string', 'max:50'],
            'postal_zip_code'=> ['nullable', 'string', 'max:20'],
            'company_email'  => ['nullable', 'email', 'max:100'],
            'company_email_2'=> ['nullable', 'email', 'max:100'],
            'company_mobile' => ['nullable', 'string', 'max:20'],
            'industry_id'    => ['required', 'integer', 'exists:inv_industries,id'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'company_type'    => 'company type',
            'company_name'    => 'company name',
            'registration_no' => 'registration number',
            'tax_reg_no'      => 'tax registration number',
            'street_address'  => 'street address',
            'postal_zip_code' => 'postal / zip code',
            'company_email'   => 'company email',
            'company_email_2' => 'company email 2',
            'company_mobile'  => 'company mobile',
            'industry_id'     => 'industry',
        ];
    }
}
