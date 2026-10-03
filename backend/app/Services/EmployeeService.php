<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\EmployeeData;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class EmployeeService
{
    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = Employee::with('location:id,location_name')->orderBy('employee_name');

        if (! empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(fn ($q) => $q
                ->where('employee_name', 'like', $term)
                ->orWhere('employee_code', 'like', $term)
                ->orWhere('designation', 'like', $term));
        }

        if (! empty($filters['department'])) {
            $query->where('department', $filters['department']);
        }

        if (! empty($filters['location_id'])) {
            $query->where('location_id', (int) $filters['location_id']);
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $query->paginate($perPage);
    }

    public function all(): Collection
    {
        return Employee::with('location:id,location_name')
            ->where('is_active', true)
            ->orderBy('employee_name')
            ->get();
    }

    public function create(EmployeeData $data): Employee
    {
        return Employee::create($this->toAttributes($data))->load('location:id,location_name');
    }

    public function update(Employee $employee, EmployeeData $data): Employee
    {
        $employee->update($this->toAttributes($data));

        return $employee->fresh()->load('location:id,location_name');
    }

    public function delete(Employee $employee): void
    {
        $employee->delete();
    }

    /** @return array<string, mixed> */
    private function toAttributes(EmployeeData $data): array
    {
        return [
            'employee_code' => $data->employee_code,
            'employee_name' => $data->employee_name,
            'designation'   => $data->designation,
            'department'    => $data->department,
            'location_id'   => $data->location_id,
            'mobile'        => $data->mobile,
            'email'         => $data->email,
            'is_active'     => $data->is_active,
        ];
    }
}
