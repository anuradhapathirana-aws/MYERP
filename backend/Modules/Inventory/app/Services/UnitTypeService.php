<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\DTOs\UnitTypeData;
use Modules\Inventory\Models\UnitType;

class UnitTypeService
{
    public function paginate(int $perPage = 50): LengthAwarePaginator
    {
        return UnitType::with('category')
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function find(int $id): UnitType
    {
        return UnitType::with('category')->findOrFail($id);
    }

    public function create(UnitTypeData $data): UnitType
    {
        $unitType = UnitType::create([
            'unit_category_id' => $data->unitCategoryId,
            'name'             => $data->name,
            'symbol'           => $data->symbol,
            'country'          => $data->country,
            'unit_position'    => $data->unitPosition,
        ]);

        return $unitType->load('category');
    }

    public function update(UnitType $unitType, UnitTypeData $data): UnitType
    {
        $unitType->update([
            'unit_category_id' => $data->unitCategoryId,
            'name'             => $data->name,
            'symbol'           => $data->symbol,
            'country'          => $data->country,
            'unit_position'    => $data->unitPosition,
        ]);

        return $unitType->load('category');
    }

    public function delete(UnitType $unitType): void
    {
        $unitType->delete();
    }
}
