<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Identity
            'vehicle_code'        => $this->vehicle_code,
            'registration_number' => $this->registration_number,

            // Specifications
            'make'             => $this->make,
            'model'            => $this->model,
            'year'             => $this->year,
            'color'            => $this->color,
            'vehicle_type'     => $this->vehicle_type,
            'fuel_type'        => $this->fuel_type,
            'engine_number'    => $this->engine_number,
            'chassis_number'   => $this->chassis_number,
            'seating_capacity' => $this->seating_capacity,
            'payload_capacity' => $this->payload_capacity,

            // Compliance
            'insurance_policy_no'       => $this->insurance_policy_no,
            'insurance_expiry_date'     => $this->insurance_expiry_date?->toDateString(),
            'road_tax_expiry_date'      => $this->road_tax_expiry_date?->toDateString(),
            'emission_test_expiry_date' => $this->emission_test_expiry_date?->toDateString(),

            // Assignment — included when relation is eager-loaded
            'assigned_driver_id' => $this->assigned_driver_id,
            'assigned_driver'    => $this->whenLoaded('assignedDriver', fn () => [
                'id'          => $this->assignedDriver->id,
                'driver_code' => $this->assignedDriver->driver_code,
                'name'        => trim("{$this->assignedDriver->first_name} {$this->assignedDriver->last_name}"),
            ]),

            // Misc
            'status' => $this->status,
            'notes'  => $this->notes,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
