<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Illuminate\Foundation\Http\FormRequest;

final class DriverData
{
    public function __construct(
        public readonly string  $driverCode,
        public readonly string  $firstName,
        public readonly ?string $lastName,
        public readonly ?string $nicNumber,
        public readonly ?string $dateOfBirth,
        public readonly string  $licenseNumber,
        public readonly ?string $licenseType,
        public readonly ?string $licenseExpiryDate,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly ?string $addressLine1,
        public readonly ?string $addressLine2,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly ?string $country,
        public readonly ?string $postalCode,
        public readonly ?string $hiredDate,
        public readonly string  $status,
        public readonly ?string $notes,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        return new self(
            driverCode:        $request->validated('driver_code'),
            firstName:         $request->validated('first_name'),
            lastName:          $request->validated('last_name'),
            nicNumber:         $request->validated('nic_number'),
            dateOfBirth:       $request->validated('date_of_birth'),
            licenseNumber:     $request->validated('license_number'),
            licenseType:       $request->validated('license_type'),
            licenseExpiryDate: $request->validated('license_expiry_date'),
            phone:             $request->validated('phone'),
            email:             $request->validated('email'),
            addressLine1:      $request->validated('address_line1'),
            addressLine2:      $request->validated('address_line2'),
            city:              $request->validated('city'),
            state:             $request->validated('state'),
            country:           $request->validated('country'),
            postalCode:        $request->validated('postal_code'),
            hiredDate:         $request->validated('hired_date'),
            status:            $request->validated('status') ?? 'active',
            notes:             $request->validated('notes'),
        );
    }
}
