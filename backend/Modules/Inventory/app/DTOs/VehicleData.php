<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Illuminate\Foundation\Http\FormRequest;

final class VehicleData
{
    public function __construct(
        public readonly string   $vehicleCode,
        public readonly string   $registrationNumber,
        public readonly ?string  $make,
        public readonly ?string  $model,
        public readonly ?int     $year,
        public readonly ?string  $color,
        public readonly ?string  $vehicleType,
        public readonly ?string  $fuelType,
        public readonly ?string  $engineNumber,
        public readonly ?string  $chassisNumber,
        public readonly ?int     $seatingCapacity,
        public readonly ?float   $payloadCapacity,
        public readonly ?string  $insurancePolicyNo,
        public readonly ?string  $insuranceExpiryDate,
        public readonly ?string  $roadTaxExpiryDate,
        public readonly ?string  $emissionTestExpiryDate,
        public readonly ?int     $assignedDriverId,
        public readonly string   $status,
        public readonly ?string  $notes,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        return new self(
            vehicleCode:             $request->validated('vehicle_code'),
            registrationNumber:      $request->validated('registration_number'),
            make:                    $request->validated('make'),
            model:                   $request->validated('model'),
            year:                    $request->validated('year') !== null ? (int) $request->validated('year') : null,
            color:                   $request->validated('color'),
            vehicleType:             $request->validated('vehicle_type'),
            fuelType:                $request->validated('fuel_type'),
            engineNumber:            $request->validated('engine_number'),
            chassisNumber:           $request->validated('chassis_number'),
            seatingCapacity:         $request->validated('seating_capacity') !== null ? (int) $request->validated('seating_capacity') : null,
            payloadCapacity:         $request->validated('payload_capacity') !== null ? (float) $request->validated('payload_capacity') : null,
            insurancePolicyNo:       $request->validated('insurance_policy_no'),
            insuranceExpiryDate:     $request->validated('insurance_expiry_date'),
            roadTaxExpiryDate:       $request->validated('road_tax_expiry_date'),
            emissionTestExpiryDate:  $request->validated('emission_test_expiry_date'),
            assignedDriverId:        $request->validated('assigned_driver_id') !== null ? (int) $request->validated('assigned_driver_id') : null,
            status:                  $request->validated('status') ?? 'active',
            notes:                   $request->validated('notes'),
        );
    }
}
