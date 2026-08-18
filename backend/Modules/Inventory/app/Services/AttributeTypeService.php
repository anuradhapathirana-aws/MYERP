<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Inventory\DTOs\AttributeTypeData;
use Modules\Inventory\Models\AttributeType;

class AttributeTypeService
{
    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = AttributeType::with('category')
            ->withCount('attributes')
            ->orderBy('attribute_type_name');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('attribute_type_name', 'like', $term)
                  ->orWhere('description', 'like', $term);
            });
        }

        if (!empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (!empty($filters['product_service_type'])) {
            $query->where('product_service_type', $filters['product_service_type']);
        }

        return $query->paginate($perPage);
    }

    public function find(int $id): AttributeType
    {
        return AttributeType::with('category')
            ->withCount('attributes')
            ->findOrFail($id);
    }

    public function create(AttributeTypeData $data): AttributeType
    {
        $type = AttributeType::create([
            'category_id'          => $data->category_id,
            'product_service_type' => $data->product_service_type,
            'attribute_type_name'  => $data->attribute_type_name,
            'description'          => $data->description,
        ]);

        return $type->load('category');
    }

    public function update(AttributeType $type, AttributeTypeData $data): AttributeType
    {
        $type->update([
            'category_id'          => $data->category_id,
            'product_service_type' => $data->product_service_type,
            'attribute_type_name'  => $data->attribute_type_name,
            'description'          => $data->description,
        ]);

        return $type->load('category')->loadCount('attributes');
    }

    public function delete(AttributeType $type): void
    {
        $type->delete();
    }

    /** Lightweight list for dropdowns — returns id, attribute_type_name, and category_id. */
    public function all(): Collection
    {
        return AttributeType::orderBy('attribute_type_name')
            ->get(['id', 'attribute_type_name', 'category_id']);
    }
}
