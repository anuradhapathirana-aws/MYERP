<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\DTOs\CategoryData;
use Modules\Inventory\Models\Category;

class CategoryService
{
    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = Category::with('parent')
            ->orderBy('category_name');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('category_name', 'like', $term)
                    ->orWhere('reference_name', 'like', $term);
            });
        }

        if (!empty($filters['product_service_type'])) {
            $query->where('product_service_type', $filters['product_service_type']);
        }

        if (!empty($filters['parent_category_id'])) {
            $query->where('parent_category_id', (int) $filters['parent_category_id']);
        }

        if (!empty($filters['industry_id'])) {
            $query->where('industry_id', (int) $filters['industry_id']);
        }

        if (!empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        return $query->paginate($perPage);
    }

    public function find(int $id): Category
    {
        return Category::with(['parent', 'industry', 'company'])->findOrFail($id);
    }

    /** Flat list for dropdown — id, category_name, parent_category_id. */
    public function all(): Collection
    {
        return Category::orderBy('category_name')
            ->get(['id', 'category_name', 'parent_category_id']);
    }

    public function create(CategoryData $data): Category
    {
        return Category::create($this->toAttributes($data));
    }

    public function update(Category $category, CategoryData $data): Category
    {
        if ($data->parent_category_id !== null) {
            $this->guardCircularReference($category, $data->parent_category_id);
        }

        $category->update($this->toAttributes($data));

        return $category->fresh(['parent', 'industry', 'company']);
    }

    public function delete(Category $category): void
    {
        $category->delete();
    }

    /** @return array<string, mixed> */
    private function toAttributes(CategoryData $data): array
    {
        return [
            'product_service_type' => $data->product_service_type,
            'industry_id'          => $data->industry_id,
            'company_id'           => $data->company_id,
            'parent_category_id'   => $data->parent_category_id,
            'category_name'        => $data->category_name,
            'reference_name'       => $data->reference_name,
        ];
    }

    /** Prevent setting a descendant as the parent (circular reference). */
    private function guardCircularReference(Category $category, int $newParentId): void
    {
        if ($newParentId === $category->id) {
            throw ValidationException::withMessages([
                'parent_category_id' => ['A category cannot be its own parent.'],
            ]);
        }

        $descendantIds = $category->descendantIds();

        if (in_array($newParentId, $descendantIds, true)) {
            throw ValidationException::withMessages([
                'parent_category_id' => ['Cannot set a descendant category as the parent (circular reference).'],
            ]);
        }
    }
}
