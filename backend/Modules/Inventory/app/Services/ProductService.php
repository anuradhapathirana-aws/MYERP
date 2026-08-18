<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\ProductData;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductAttribute;
use Modules\Inventory\Models\ProductLocationStore;

class ProductService
{
    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = Product::with(['suppliers', 'salesChannels', 'category', 'baseUnit'])
            ->withExists('stockTransactions')
            ->orderBy('name');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('product_code', 'like', $term)
                    ->orWhere('ean_13', 'like', $term);
            });
        }

        if (!empty($filters['product_type'])) {
            $query->where('product_type', $filters['product_type']);
        }

        if (!empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (!empty($filters['tracking_type'])) {
            $query->where('tracking_type', $filters['tracking_type']);
        }

        if (!empty($filters['supplier_id'])) {
            $supplierId = (int) $filters['supplier_id'];
            $query->whereHas('suppliers', fn ($q) => $q->where('inv_supplier_masters.id', $supplierId));
        }

        return $query->paginate($perPage);
    }

    public function find(int $id): Product
    {
        return Product::with(['suppliers', 'salesChannels', 'category', 'location', 'productAttributes', 'locationStores', 'baseUnit'])
            ->withExists('stockTransactions')
            ->findOrFail($id);
    }

    public function create(ProductData $data): Product
    {
        return DB::transaction(function () use ($data): Product {
            $attributes = $this->toAttributes($data);
            $attributes['product_code'] = $this->generateProductCode();

            $product = Product::create($attributes);

            $this->syncSuppliers($product, $data->supplierIds);
            $this->syncCostDetails($product, $data->costDetails);
            $this->syncProductAttributes($product, $data->productAttributes);
            $this->syncLocationStores($product, $data->locationStores);

            return $product->load(['suppliers', 'salesChannels', 'productAttributes', 'locationStores', 'baseUnit']);
        });
    }

    public function update(Product $product, ProductData $data): Product
    {
        // product_code is immutable once assigned — never overwritten on update.
        $product->update($this->toAttributes($data));

        $this->syncSuppliers($product, $data->supplierIds);
        $this->syncCostDetails($product, $data->costDetails);
        $this->syncProductAttributes($product, $data->productAttributes);
        $this->syncLocationStores($product, $data->locationStores);

        return $product->load(['suppliers', 'salesChannels', 'productAttributes', 'locationStores', 'baseUnit']);
    }

    public function delete(Product $product): void
    {
        $product->delete();
    }

    /** Preview the next product code (non-locking, for display only) */
    public function nextProductCode(): string
    {
        $prefix = 'PRD-';

        $last = Product::where('product_code', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('product_code');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Atomically generate the next product code (must be called inside a DB transaction) */
    private function generateProductCode(): string
    {
        $prefix = 'PRD-';

        $last = Product::where('product_code', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('product_code');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** @param array<int> $supplierIds */
    private function syncSuppliers(Product $product, array $supplierIds): void
    {
        $product->suppliers()->sync($supplierIds);
    }

    /**
     * @param array<array<string, mixed>> $costDetails
     * Each item: { sales_channel_id, unit_type_id?, num_of_units?, cost_price?, margin?, selling_price?, sale_privileges_discount? }
     */
    private function syncCostDetails(Product $product, array $costDetails): void
    {
        $pivotData = [];

        foreach ($costDetails as $row) {
            $channelId = (int) $row['sales_channel_id'];
            if ($channelId <= 0) {
                continue;
            }

            $pivotData[$channelId] = [
                'unit_type_id'                   => !empty($row['unit_type_id']) ? (int) $row['unit_type_id'] : null,
                'num_of_units'                   => $row['num_of_units']                   ?? null,
                'cost_price'                     => $row['cost_price']                     ?? null,
                'margin'                         => $row['margin']                         ?? null,
                'margin_type'                    => $row['margin_type']                    ?? 'percentage',
                'selling_price'                  => $row['selling_price']                  ?? null,
                'max_price'                      => $row['max_price']                      ?? null,
                'min_price'                      => $row['min_price']                      ?? null,
                'wholesale_price'                => $row['wholesale_price']                ?? null,
                'sale_privileges_discount'       => $row['sale_privileges_discount']       ?? null,
                'purchasing_privileges_discount' => $row['purchasing_privileges_discount'] ?? null,
            ];
        }

        $product->salesChannels()->sync($pivotData);
    }

    /** @param array<array{location_id: ?int, store_id: ?int}> $locationStores */
    private function syncLocationStores(Product $product, array $locationStores): void
    {
        $incoming = collect($locationStores)
            ->filter(fn(array $row) => !empty($row['location_id']) || !empty($row['store_id']))
            ->map(fn(array $row) => [
                'location_id' => !empty($row['location_id']) ? (int) $row['location_id'] : null,
                'store_id'    => !empty($row['store_id'])    ? (int) $row['store_id']    : null,
            ])
            ->values();

        $incomingKeys = $incoming
            ->map(fn($r) => "{$r['location_id']}:{$r['store_id']}")
            ->all();

        $existing     = $product->locationStores()->get(['location_id', 'store_id']);
        $existingKeys = $existing
            ->map(fn($r) => "{$r->location_id}:{$r->store_id}")
            ->all();

        // Delete only rows removed from the list — preserves current_stock on kept rows
        foreach ($existing as $row) {
            if (!in_array("{$row->location_id}:{$row->store_id}", $incomingKeys, true)) {
                $product->locationStores()
                    ->where('location_id', $row->location_id)
                    ->where('store_id',    $row->store_id)
                    ->delete();
            }
        }

        // Insert only genuinely new pairs with current_stock = 0
        $now      = now();
        $toInsert = $incoming
            ->filter(fn($r) => !in_array("{$r['location_id']}:{$r['store_id']}", $existingKeys, true))
            ->map(fn($r) => [
                'product_id'  => $product->id,
                'location_id' => $r['location_id'],
                'store_id'    => $r['store_id'],
                'created_at'  => $now,
                'updated_at'  => $now,
            ])
            ->values()
            ->all();

        if (!empty($toInsert)) {
            ProductLocationStore::insert($toInsert);
        }
    }

    /** @param array<array{attribute_type_id: int, attribute_id: int}> $productAttributes */
    private function syncProductAttributes(Product $product, array $productAttributes): void
    {
        $product->productAttributes()->delete();

        $rows = collect($productAttributes)
            ->filter(fn(array $row) => !empty($row['attribute_id']) && !empty($row['attribute_type_id']))
            ->unique('attribute_id')
            ->values()
            ->map(fn(array $row) => [
                'product_id'        => $product->id,
                'attribute_type_id' => (int) $row['attribute_type_id'],
                'attribute_id'      => (int) $row['attribute_id'],
                'created_at'        => now(),
                'updated_at'        => now(),
            ])
            ->all();

        if (!empty($rows)) {
            ProductAttribute::insert($rows);
        }
    }

    /** @return array<string, mixed> */
    private function toAttributes(ProductData $data): array
    {
        return [
            'reference_no'             => $data->referenceNo,
            'ean_13'                   => $data->ean13,
            'name'                     => $data->name,
            'display_name'             => $data->displayName,
            'product_type'             => $data->productType,
            'description'              => $data->description,
            'category_id'              => $data->categoryId,
            'location_id'              => $data->locationId,
            'base_unit_type_id'        => $data->baseUnitTypeId,
            'reorder_level'            => $data->reorderLevel,
            'reorder_qty'              => $data->reorderQty,
            'reorder_period'           => $data->reorderPeriod,
            'stock_releasing_method'   => $data->stockReleasingMethod,
            'tracking_type'            => $data->trackingType,
            'lock_purchase'            => $data->lockPurchase,
            'allow_complimentary_items' => $data->allowComplementaryItems,
            'free_issue'               => $data->freeIssue,
            'allow_minus'              => $data->allowMinus,
            'not_allow_direct_sale'    => $data->notAllowDirectSale,
            'non_returnable'           => $data->nonReturnable,
            'is_empty'                 => $data->isEmpty,
            'service_charge'           => $data->serviceCharge,
            'loyalty'                  => $data->loyalty,
            'is_batch'                 => $data->isBatch,
            'is_serial'                => $data->isSerial,
        ];
    }
}
