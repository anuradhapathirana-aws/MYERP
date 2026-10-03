<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\SupplierGroupData;
use App\Models\SupplierGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class SupplierGroupService
{
    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = SupplierGroup::withCount('suppliers')
            ->orderBy('sort_order')
            ->orderBy('name');

        if (! empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('code', 'like', $term));
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        return $query->paginate($perPage);
    }

    public function all(): Collection
    {
        return SupplierGroup::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function create(SupplierGroupData $data): SupplierGroup
    {
        return SupplierGroup::create($this->toAttributes($data));
    }

    public function update(SupplierGroup $group, SupplierGroupData $data): SupplierGroup
    {
        $group->update($this->toAttributes($data));

        return $group->fresh();
    }

    /**
     * A group still assigned to suppliers must not be deleted — doing so would
     * orphan those suppliers' default posting accounts.
     */
    public function delete(SupplierGroup $group): void
    {
        $inUse = $group->suppliers()->count();

        if ($inUse > 0) {
            throw ValidationException::withMessages([
                'id' => "This supplier group cannot be deleted because {$inUse} supplier(s) are assigned to it. Reassign them first.",
            ]);
        }

        $group->delete();
    }

    /** @return array<string, mixed> */
    private function toAttributes(SupplierGroupData $data): array
    {
        return [
            'code'                       => $data->code,
            'name'                       => $data->name,
            'description'                => $data->description,
            'default_payable_account_id' => $data->default_payable_account_id,
            'default_expense_account_id' => $data->default_expense_account_id,
            'is_active'                  => $data->is_active,
            'sort_order'                 => $data->sort_order,
        ];
    }
}
