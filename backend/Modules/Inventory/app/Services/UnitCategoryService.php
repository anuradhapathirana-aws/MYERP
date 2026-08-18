<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\UnitCategoryData;
use Modules\Inventory\Models\UnitCategory;

class UnitCategoryService
{
    public function paginate(int $perPage = 50): LengthAwarePaginator
    {
        return UnitCategory::withCount('unitTypes')
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function find(int $id): UnitCategory
    {
        return UnitCategory::withCount('unitTypes')->findOrFail($id);
    }

    public function create(UnitCategoryData $data): UnitCategory
    {
        return UnitCategory::create([
            'name'        => $data->name,
            'description' => $data->description,
        ]);
    }

    public function update(UnitCategory $category, UnitCategoryData $data): UnitCategory
    {
        $category->update([
            'name'        => $data->name,
            'description' => $data->description,
        ]);

        return $category->loadCount('unitTypes');
    }

    public function delete(UnitCategory $category): void
    {
        $category->delete();
    }

    /** @return UnitCategory[] */
    public function createMany(array $names, ?string $description = null): array
    {
        return \Illuminate\Support\Facades\DB::transaction(
            fn (): array => array_map(
                fn (string $name): UnitCategory => UnitCategory::create([
                    'name'        => $name,
                    'description' => $description,
                ]),
                $names,
            ),
        );
    }

    /** Lightweight list for dropdowns — returns id, name, and the default-category flag. */
    public function all(): \Illuminate\Database\Eloquent\Collection
    {
        return UnitCategory::orderBy('name')->get(['id', 'name', 'is_default']);
    }

    /** Set this category as the single system default; clears any previous default. */
    public function setDefault(UnitCategory $category): UnitCategory
    {
        DB::transaction(function () use ($category): void {
            UnitCategory::where('is_default', true)->update(['is_default' => false]);
            $category->update(['is_default' => true]);
        });

        return $category->loadCount('unitTypes');
    }

    /** Clear the default from a category (if it is currently default). */
    public function clearDefault(UnitCategory $category): UnitCategory
    {
        if ($category->is_default) {
            $category->update(['is_default' => false]);
        }

        return $category->loadCount('unitTypes');
    }
}
