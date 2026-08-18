<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Identity
            'driver_code'   => ['required', 'string', 'max:20', Rule::unique('inv_drivers', 'driver_code')->whereNull('deleted_at')],
            'first_name'    => ['required', 'string', 'max:100'],
            'last_name'     => ['nullable', 'string', 'max:100'],
            'nic_number'    => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date'],

            // Licence
            'license_number'      => ['required', 'string', 'max:50', Rule::unique('inv_drivers', 'license_number')->whereNull('deleted_at')],
            'license_type'        => ['nullable', 'string', 'max:50'],
            'license_expiry_date' => ['nullable', 'date'],

            // Contact
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:100'],

            // Address
            'address_line1' => ['nullable', 'string', 'max:150'],
            'address_line2' => ['nullable', 'string', 'max:150'],
            'city'          => ['nullable', 'string', 'max:100'],
            'state'         => ['nullable', 'string', 'max:100'],
            'country'       => ['nullable', 'string', 'max:100'],
            'postal_code'   => ['nullable', 'string', 'max:20'],

            // Employment
            'hired_date' => ['nullable', 'date'],
            'status'     => ['nullable', Rule::in(['active', 'inactive', 'suspended'])],
            'notes'      => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'driver_code'         => 'driver code',
            'first_name'          => 'first name',
            'last_name'           => 'last name',
            'nic_number'          => 'NIC number',
            'date_of_birth'       => 'date of birth',
            'license_number'      => 'license number',
            'license_type'        => 'license type',
            'license_expiry_date' => 'license expiry date',
            'phone'               => 'phone',
            'email'               => 'email',
            'address_line1'       => 'address line 1',
            'address_line2'       => 'address line 2',
            'city'                => 'city',
            'state'               => 'state',
            'country'             => 'country',
            'postal_code'         => 'postal code',
            'hired_date'          => 'hired date',
            'status'              => 'status',
            'notes'               => 'notes',
        ];
    }
}
