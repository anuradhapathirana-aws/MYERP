<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\DTOs\DriverData;
use Modules\Inventory\Models\Driver;

class DriverService
{
    public function paginate(int $perPage = 50): LengthAwarePaginator
    {
        return Driver::orderBy('first_name')->orderBy('last_name')->paginate($perPage);
    }

    public function find(int $id): Driver
    {
        return Driver::findOrFail($id);
    }

    public function create(DriverData $data): Driver
    {
        return Driver::create($this->toAttributes($data));
    }

    public function update(Driver $driver, DriverData $data): Driver
    {
        $driver->update($this->toAttributes($data));

        return $driver->fresh();
    }

    public function delete(Driver $driver): void
    {
        $driver->delete();
    }

    /** Lightweight list for dropdowns — id + full_name only. */
    public function all(): Collection
    {
        return Driver::orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'driver_code']);
    }

    private function toAttributes(DriverData $data): array
    {
        return [
            'driver_code'         => $data->driverCode,
            'first_name'          => $data->firstName,
            'last_name'           => $data->lastName,
            'nic_number'          => $data->nicNumber,
            'date_of_birth'       => $data->dateOfBirth,
            'license_number'      => $data->licenseNumber,
            'license_type'        => $data->licenseType,
            'license_expiry_date' => $data->licenseExpiryDate,
            'phone'               => $data->phone,
            'email'               => $data->email,
            'address_line1'       => $data->addressLine1,
            'address_line2'       => $data->addressLine2,
            'city'                => $data->city,
            'state'               => $data->state,
            'country'             => $data->country,
            'postal_code'         => $data->postalCode,
            'hired_date'          => $data->hiredDate,
            'status'              => $data->status,
            'notes'               => $data->notes,
        ];
    }
}
