<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // General
            'supplier_name'             => ['required', 'string', 'max:100'],
            'reference_no'              => ['nullable', 'string', 'max:50'],
            'supplier_type'             => ['required', 'string', Rule::in(['Trade', 'Service'])],
            'check_writer_name'         => ['nullable', 'string', 'max:100'],

            // Contact
            'mobile'                    => ['required', 'string', 'max:20'],
            'land_line'                 => ['required', 'string', 'max:20'],
            'email'                     => ['required', 'email', 'max:100'],
            'wechat'                    => ['nullable', 'string', 'max:100'],
            'whatsapp'                  => ['nullable', 'string', 'max:20'],
            'fax'                       => ['nullable', 'string', 'max:20'],
            'website'                   => ['nullable', 'url', 'max:255'],

            // Billing address
            'bil_address_line_1'        => ['required', 'string', 'max:100'],
            'bil_address_line_2'        => ['nullable', 'string', 'max:100'],
            'bil_address_line_3'        => ['nullable', 'string', 'max:100'],
            'bil_city'                  => ['nullable', 'string', 'max:50'],
            'bil_postal_code'           => ['nullable', 'string', 'max:20'],
            'bil_country'               => ['nullable', 'string', 'max:50'],
            'bil_state_province'        => ['nullable', 'string', 'max:50'],

            // Tax
            'tax_type'                  => ['nullable', 'string', 'max:50'],
            'tax_no'                    => ['nullable', 'string', 'max:50'],
            'tax_regis_no'              => ['nullable', 'string', 'max:50'],

            // Financial terms
            'credit_limit'              => ['nullable', 'numeric', 'min:0'],
            'credit_period'             => ['nullable', 'integer', 'min:0'],
            'privileges_discount'       => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Banking
            'bank_name'                 => ['nullable', 'string', 'max:100'],
            'bank_branch'               => ['nullable', 'string', 'max:100'],
            'bank_acc_holder_name'      => ['nullable', 'string', 'max:100'],
            'bank_acc_no'               => ['nullable', 'string', 'max:50'],

            // Contact person
            'contact_person_name'       => ['required', 'string', 'max:100'],
            'contact_person_designation'=> ['nullable', 'string', 'max:100'],
            'contact_person_mobile'     => ['required', 'string', 'max:20'],
            'contact_person_email'      => ['nullable', 'email', 'max:100'],
            'contact_person_fax'        => ['nullable', 'string', 'max:20'],
        ];
    }

    public function attributes(): array
    {
        return [
            'supplier_name'             => 'supplier name',
            'reference_no'              => 'reference number',
            'supplier_type'             => 'supplier type',
            'check_writer_name'         => 'check writer name',
            'bil_address_line_1'        => 'billing address line 1',
            'bil_address_line_2'        => 'billing address line 2',
            'bil_address_line_3'        => 'billing address line 3',
            'bil_city'                  => 'billing city',
            'bil_postal_code'           => 'billing postal code',
            'bil_country'               => 'billing country',
            'bil_state_province'        => 'billing state/province',
            'tax_type'                  => 'tax type',
            'tax_no'                    => 'tax number',
            'tax_regis_no'              => 'tax registration number',
            'credit_limit'              => 'credit limit',
            'credit_period'             => 'credit period',
            'privileges_discount'       => 'privileges discount',
            'bank_acc_holder_name'      => 'bank account holder name',
            'bank_acc_no'               => 'bank account number',
            'contact_person_name'       => 'contact person name',
            'contact_person_designation'=> 'contact person designation',
            'contact_person_mobile'     => 'contact person mobile',
            'contact_person_email'      => 'contact person email',
            'contact_person_fax'        => 'contact person fax',
        ];
    }
}
