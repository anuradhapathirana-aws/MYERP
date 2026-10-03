<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Http\Requests\EmployeeRequest;

final class EmployeeData
{
    public function __construct(
        public readonly string  $employee_code,
        public readonly string  $employee_name,
        public readonly ?string $designation,
        public readonly ?string $department,
        public readonly ?int    $location_id,
        public readonly ?string $mobile,
        public readonly ?string $email,
        public readonly bool    $is_active,
    ) {}

    public static function fromRequest(EmployeeRequest $request): self
    {
        $locationId = $request->input('location_id');

        return new self(
            employee_code: strtoupper(trim((string) $request->input('employee_code'))),
            employee_name: trim((string) $request->input('employee_name')),
            designation:   self::nullableString($request->input('designation')),
            department:    self::nullableString($request->input('department')),
            location_id:   $locationId === null || $locationId === '' ? null : (int) $locationId,
            mobile:        self::nullableString($request->input('mobile')),
            email:         self::nullableString($request->input('email')),
            is_active:     (bool) $request->input('is_active', true),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === '' || $value === null ? null : (string) $value;
    }
}
