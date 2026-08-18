<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,

            // Identity
            'driver_code'   => $this->driver_code,
            'first_name'    => $this->first_name,
            'last_name'     => $this->last_name,
            'full_name'     => trim("{$this->first_name} {$this->last_name}"),
            'nic_number'    => $this->nic_number,
            'date_of_birth' => $this->date_of_birth?->toDateString(),

            // Licence
            'license_number'      => $this->license_number,
            'license_type'        => $this->license_type,
            'license_expiry_date' => $this->license_expiry_date?->toDateString(),

            // Contact
            'phone' => $this->phone,
            'email' => $this->email,

            // Address
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'city'          => $this->city,
            'state'         => $this->state,
            'country'       => $this->country,
            'postal_code'   => $this->postal_code,

            // Employment
            'hired_date' => $this->hired_date?->toDateString(),
            'status'     => $this->status,
            'notes'      => $this->notes,

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
