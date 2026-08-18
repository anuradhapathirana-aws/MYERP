<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $locationId = $this->route('location')?->id;
        $same       = (bool) $this->input('billing_same_as_location', false);

        $billingRequired = $same ? 'nullable' : 'required';

        return [
            // Basic Details
            'company_id'               => ['required', 'integer', 'exists:inv_companies,id'],
            'industry_id'              => ['required', 'integer', 'exists:inv_industries,id'],
            'parent_location_id'       => ['nullable', 'integer', 'exists:inv_locations,id'],
            'location_code'            => [
                'required',
                'string',
                'max:50',
                Rule::unique('inv_locations', 'location_code')->ignore($locationId),
            ],
            'location_name'            => ['required', 'string', 'max:100'],
            'location_type'            => ['nullable', 'string', 'max:50'],
            'country'                  => ['required', 'string', 'max:100'],

            // Location Address
            'loc_street_address'       => ['required', 'string', 'max:150'],
            'loc_city'                 => ['required', 'string', 'max:50'],
            'loc_country'              => ['required', 'string', 'max:100'],
            'loc_state'                => ['required', 'string', 'max:50'],
            'loc_postal_zip_code'      => ['required', 'string', 'max:20'],

            // Billing Address
            'billing_same_as_location' => ['boolean'],
            'bill_street_address'      => ['nullable', 'string', 'max:150'],
            'bill_city'                => [$billingRequired, 'string', 'max:50'],
            'bill_country'             => [$billingRequired, 'string', 'max:100'],
            'bill_state'               => ['nullable', 'string', 'max:50'],
            'bill_postal_zip_code'     => [$billingRequired, 'string', 'max:20'],

            // Contact
            'company_email'            => ['nullable', 'email', 'max:100'],
            'customer_facing_email'    => ['nullable', 'email', 'max:100'],
            'company_phone'            => ['nullable', 'string', 'max:30'],
            'mobile'                   => ['nullable', 'string', 'max:30'],
            'fax'                      => ['nullable', 'string', 'max:30'],
            'website'                  => ['nullable', 'url', 'max:255'],
            'longitude'                => ['nullable', 'numeric', 'between:-180,180'],
            'latitude'                 => ['nullable', 'numeric', 'between:-90,90'],
            'map_url'                  => ['nullable', 'string', 'max:500'],

            // Advanced Settings
            'date_format'              => ['nullable', 'string', 'max:30'],
            'number_format'            => ['nullable', 'string', 'max:30'],
            'time_format'              => ['nullable', 'string', 'max:30'],
            'float_precision'          => ['nullable', 'integer', 'min:0', 'max:10'],
            'base_currency'            => ['required', 'string', 'max:10'],
            'time_zone'                => ['nullable', 'string', 'max:100'],
            'financial_year'           => ['required', 'string', 'max:50'],
            'open_hours_from'          => ['nullable', 'date_format:H:i'],
            'open_hours_to'            => ['nullable', 'date_format:H:i', 'after:open_hours_from'],

            // Module & Inventory
            'available_modules'        => ['nullable', 'array'],
            'available_modules.*'      => ['string', 'in:HRM,Account,CRM,Inventory'],
            'stock_releasing_method'   => ['required', 'string', 'in:LIFO,FIFO,AVG'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'company_id'             => 'company',
            'industry_id'            => 'industry',
            'parent_location_id'     => 'parent location',
            'location_code'          => 'location code',
            'location_name'          => 'location name',
            'location_type'          => 'location type',
            'country'                => 'country',
            'loc_street_address'     => 'street address',
            'loc_city'               => 'city',
            'loc_country'            => 'location country',
            'loc_state'              => 'state',
            'loc_postal_zip_code'    => 'postal / zip code',
            'bill_city'              => 'billing city',
            'bill_country'           => 'billing country',
            'bill_postal_zip_code'   => 'billing postal / zip code',
            'company_email'          => 'company email',
            'customer_facing_email'  => 'customer-facing email',
            'company_phone'          => 'company phone',
            'base_currency'          => 'base currency',
            'financial_year'         => 'financial year',
            'open_hours_from'        => 'open hours from',
            'open_hours_to'          => 'open hours to',
            'available_modules'      => 'available modules',
            'stock_releasing_method' => 'stock releasing method',
        ];
    }
}
