<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\DTOs\StoreData;
use Modules\Inventory\Models\Store;

class StoreService
{
    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = Store::with(['storeType', 'location', 'parentStore'])
            ->orderBy('store_name');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('store_name', 'like', $term)
                    ->orWhere('store_code', 'like', $term);
            });
        }

        if (!empty($filters['store_type_id'])) {
            $query->where('store_type_id', (int) $filters['store_type_id']);
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (!empty($filters['location_id'])) {
            $query->where('location_id', (int) $filters['location_id']);
        }

        return $query->paginate($perPage);
    }

    public function all(): Collection
    {
        return Store::select('id', 'location_id', 'parent_store_id', 'store_code', 'store_name')
            ->where('is_active', true)
            ->orderBy('store_name')
            ->get();
    }

    public function create(StoreData $data): Store
    {
        $store = Store::create($this->toAttributes($data));

        return $store->load(['storeType', 'location']);
    }

    public function update(Store $store, StoreData $data): Store
    {
        $store->update($this->toAttributes($data));

        return $store->fresh(['storeType', 'location']);
    }

    public function delete(Store $store): void
    {
        $store->delete();
    }

    /** @return array<string, mixed> */
    private function toAttributes(StoreData $data): array
    {
        return [
            'store_type_id'   => $data->store_type_id,
            'location_id'     => $data->location_id,
            'parent_store_id' => $data->parent_store_id,
            'store_code'     => $data->store_code,
            'store_name'     => $data->store_name,
            'uom'            => $data->uom,
            'capacity'       => $data->capacity,
            'address_line_1' => $data->address_line_1,
            'address_line_2' => $data->address_line_2,
            'city'           => $data->city,
            'state'          => $data->state,
            'country'        => $data->country,
            'postal_code'    => $data->postal_code,
            'manager_name'   => $data->manager_name,
            'phone'          => $data->phone,
            'email'          => $data->email,
            'description'    => $data->description,
            'is_active'      => $data->is_active,
        ];
    }
}
