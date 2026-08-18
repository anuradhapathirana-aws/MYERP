<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $vehicleId = $this->route('vehicle_master');

        return [
            // Identity
            'vehicle_code'        => ['required', 'string', 'max:20', Rule::unique('inv_vehicle_masters', 'vehicle_code')->ignore($vehicleId)->whereNull('deleted_at')],
            'registration_number' => ['required', 'string', 'max:50', Rule::unique('inv_vehicle_masters', 'registration_number')->ignore($vehicleId)->whereNull('deleted_at')],

            // Specifications
            'make'             => ['nullable', 'string', 'max:100'],
            'model'            => ['nullable', 'string', 'max:100'],
            'year'             => ['nullable', 'integer', 'min:1900', 'max:' . (date('Y') + 1)],
            'color'            => ['nullable', 'string', 'max:50'],
            'vehicle_type'     => ['nullable', Rule::in(['Car', 'Van', 'Truck', 'Bus', 'Motorcycle', 'Heavy Truck'])],
            'fuel_type'        => ['nullable', Rule::in(['Petrol', 'Diesel', 'Electric', 'Hybrid', 'CNG'])],
            'engine_number'    => ['nullable', 'string', 'max:100'],
            'chassis_number'   => ['nullable', 'string', 'max:100'],
            'seating_capacity' => ['nullable', 'integer', 'min:1', 'max:255'],
            'payload_capacity' => ['nullable', 'numeric', 'min:0'],

            // Compliance
            'insurance_policy_no'       => ['nullable', 'string', 'max:50'],
            'insurance_expiry_date'     => ['nullable', 'date'],
            'road_tax_expiry_date'      => ['nullable', 'date'],
            'emission_test_expiry_date' => ['nullable', 'date'],

            // Assignment
            'assigned_driver_id' => ['nullable', 'integer', 'exists:inv_drivers,id'],

            // Misc
            'status' => ['nullable', Rule::in(['active', 'inactive', 'under_maintenance'])],
            'notes'  => ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'vehicle_code'              => 'vehicle code',
            'registration_number'       => 'registration number',
            'make'                      => 'make',
            'model'                     => 'model',
            'year'                      => 'year',
            'color'                     => 'color',
            'vehicle_type'              => 'vehicle type',
            'fuel_type'                 => 'fuel type',
            'engine_number'             => 'engine number',
            'chassis_number'            => 'chassis number',
            'seating_capacity'          => 'seating capacity',
            'payload_capacity'          => 'payload capacity',
            'insurance_policy_no'       => 'insurance policy number',
            'insurance_expiry_date'     => 'insurance expiry date',
            'road_tax_expiry_date'      => 'road tax expiry date',
            'emission_test_expiry_date' => 'emission test expiry date',
            'assigned_driver_id'        => 'assigned driver',
            'status'                    => 'status',
            'notes'                     => 'notes',
        ];
    }
}
