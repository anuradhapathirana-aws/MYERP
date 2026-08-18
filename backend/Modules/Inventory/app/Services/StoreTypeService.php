<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\DTOs\StoreTypeData;
use Modules\Inventory\Models\StoreType;

class StoreTypeService
{
    public function paginate(int $perPage = 50): LengthAwarePaginator
    {
        return StoreType::orderBy('store_type_name')->paginate($perPage);
    }

    public function all(): Collection
    {
        return StoreType::select('id', 'store_type_name')
            ->where('is_active', true)
            ->orderBy('store_type_name')
            ->get();
    }

    public function create(StoreTypeData $data): StoreType
    {
        return StoreType::create($this->toAttributes($data));
    }

    public function update(StoreType $storeType, StoreTypeData $data): StoreType
    {
        $storeType->update($this->toAttributes($data));

        return $storeType->fresh();
    }

    public function delete(StoreType $storeType): void
    {
        $storeType->delete();
    }

    /** @return array<string, mixed> */
    private function toAttributes(StoreTypeData $data): array
    {
        return [
            'store_type_name' => $data->store_type_name,
            'description'     => $data->description,
            'is_active'       => $data->is_active,
        ];
    }
}
