<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\DTOs\VehicleData;
use Modules\Inventory\Models\VehicleMaster;

class VehicleService
{
    public function paginate(int $perPage = 50): LengthAwarePaginator
    {
        return VehicleMaster::with('assignedDriver')
            ->orderBy('vehicle_code')
            ->paginate($perPage);
    }

    public function find(int $id): VehicleMaster
    {
        return VehicleMaster::with('assignedDriver')->findOrFail($id);
    }

    public function create(VehicleData $data): VehicleMaster
    {
        $vehicle = VehicleMaster::create($this->toAttributes($data));

        return $vehicle->load('assignedDriver');
    }

    public function update(VehicleMaster $vehicle, VehicleData $data): VehicleMaster
    {
        $vehicle->update($this->toAttributes($data));

        return $vehicle->fresh('assignedDriver');
    }

    public function delete(VehicleMaster $vehicle): void
    {
        $vehicle->delete();
    }

    /** Lightweight list for dropdowns — id + code + registration only. */
    public function all(): Collection
    {
        return VehicleMaster::orderBy('vehicle_code')
            ->get(['id', 'vehicle_code', 'registration_number', 'make', 'model']);
    }

    private function toAttributes(VehicleData $data): array
    {
        return [
            'vehicle_code'              => $data->vehicleCode,
            'registration_number'       => $data->registrationNumber,
            'make'                      => $data->make,
            'model'                     => $data->model,
            'year'                      => $data->year,
            'color'                     => $data->color,
            'vehicle_type'              => $data->vehicleType,
            'fuel_type'                 => $data->fuelType,
            'engine_number'             => $data->engineNumber,
            'chassis_number'            => $data->chassisNumber,
            'seating_capacity'          => $data->seatingCapacity,
            'payload_capacity'          => $data->payloadCapacity,
            'insurance_policy_no'       => $data->insurancePolicyNo,
            'insurance_expiry_date'     => $data->insuranceExpiryDate,
            'road_tax_expiry_date'      => $data->roadTaxExpiryDate,
            'emission_test_expiry_date' => $data->emissionTestExpiryDate,
            'assigned_driver_id'        => $data->assignedDriverId,
            'status'                    => $data->status,
            'notes'                     => $data->notes,
        ];
    }
}
