<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\BatchStatus;
use Modules\Inventory\Models\Batch;

class BatchService
{
    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = Batch::with(['product', 'supplier'])
            ->orderByDesc('received_date')
            ->orderByDesc('id');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term): void {
                $q->where('batch_no', 'like', $term)
                  ->orWhere('supplier_batch_no', 'like', $term)
                  ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $term)
                      ->orWhere('product_code', 'like', $term));
            });
        }

        if (!empty($filters['product_id'])) {
            $query->where('product_id', (int) $filters['product_id']);
        }

        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['expiry_from'])) {
            $query->whereDate('expiry_date', '>=', $filters['expiry_from']);
        }

        if (!empty($filters['expiry_to'])) {
            $query->whereDate('expiry_date', '<=', $filters['expiry_to']);
        }

        return $query->paginate($perPage);
    }

    public function find(int $id): Batch
    {
        return Batch::with(['product', 'supplier'])->findOrFail($id);
    }

    public function nextBatchNo(int $productId): string
    {
        return $this->generateBatchNo($productId);
    }

    public function updateStatus(Batch $batch, BatchStatus $status): Batch
    {
        return DB::transaction(function () use ($batch, $status): Batch {
            $batch->update(['status' => $status]);
            return $batch->fresh(['product', 'supplier']);
        });
    }

    private function generateBatchNo(int $productId): string
    {
        $yearMonth = now()->format('Ym');
        $prefix    = "BAT-{$yearMonth}-";

        $last = Batch::withTrashed()
            ->where('batch_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('batch_no');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
