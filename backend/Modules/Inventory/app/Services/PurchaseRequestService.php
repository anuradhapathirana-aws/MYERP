<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\PurchaseRequestData;
use Modules\Inventory\Enums\PurchaseRequestStatus;
use Modules\Inventory\Models\PurchaseRequest;
use Modules\Inventory\Models\PurchaseRequestItem;

class PurchaseRequestService
{
    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = PurchaseRequest::with(['sourceLocation', 'sourceStore', 'targetLocation', 'targetStore', 'items'])
            ->orderByDesc('request_date')
            ->orderByDesc('id');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term): void {
                $q->where('pr_no', 'like', $term)
                  ->orWhere('reference_no', 'like', $term)
                  ->orWhere('purpose', 'like', $term);
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['store_id'])) {
            $query->where(function ($q) use ($filters): void {
                $q->where('source_store_id', (int) $filters['store_id'])
                  ->orWhere('target_store_id', (int) $filters['store_id']);
            });
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('request_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('request_date', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    public function find(int $id): PurchaseRequest
    {
        return PurchaseRequest::with([
            'items.product',
            'items.unit',
            'items.attribute',
            'sourceLocation',
            'sourceStore',
            'targetLocation',
            'targetStore',
            'customer',
        ])->findOrFail($id);
    }

    public function create(PurchaseRequestData $data): PurchaseRequest
    {
        return DB::transaction(function () use ($data): PurchaseRequest {
            $status = PurchaseRequestStatus::from($data->status);

            $pr = PurchaseRequest::create([
                'pr_no'              => $this->generatePrNo(),
                'reference_no'       => $this->generateReferenceNo(),
                'request_date'       => $data->requestDate,
                'required_date'      => $data->requiredDate,
                'purpose'            => $data->purpose,
                'source_location_id' => $data->sourceLocationId,
                'source_store_id'    => $data->sourceStoreId,
                'target_location_id' => $data->targetLocationId,
                'target_store_id'    => $data->targetStoreId,
                'customer_id'        => $data->customerId,
                'transport_mode'     => $data->transportMode,
                'remarks'            => $data->remarks,
                'status'             => $status,
                'requested_by'       => auth()->id(),
            ]);

            $this->syncItems($pr, $data->items);

            return $pr->load(['items.product', 'items.unit', 'items.attribute', 'sourceLocation', 'sourceStore', 'targetLocation', 'targetStore', 'customer']);
        });
    }

    public function update(PurchaseRequest $pr, PurchaseRequestData $data): PurchaseRequest
    {
        // Allow editing draft or approved PRs
        if (!in_array($pr->status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::Approved])) {
            abort(422, 'Only draft or approved purchase requests can be edited.');
        }

        return DB::transaction(function () use ($pr, $data): PurchaseRequest {
            $status = PurchaseRequestStatus::from($data->status);

            $pr->update([
                'request_date'       => $data->requestDate,
                'required_date'      => $data->requiredDate,
                'purpose'            => $data->purpose,
                'source_location_id' => $data->sourceLocationId,
                'source_store_id'    => $data->sourceStoreId,
                'target_location_id' => $data->targetLocationId,
                'target_store_id'    => $data->targetStoreId,
                'customer_id'        => $data->customerId,
                'transport_mode'     => $data->transportMode,
                'remarks'            => $data->remarks,
                'status'             => $status,
            ]);

            $this->syncItems($pr, $data->items);

            return $pr->load(['items.product', 'items.unit', 'items.attribute', 'sourceLocation', 'sourceStore', 'targetLocation', 'targetStore', 'customer']);
        });
    }

    public function approve(PurchaseRequest $pr, ?string $remarks = null): PurchaseRequest
    {
        if ($pr->status !== PurchaseRequestStatus::Submitted) {
            abort(422, 'Only submitted purchase requests can be approved.');
        }

        $pr->update([
            'status'      => PurchaseRequestStatus::Approved,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'remarks'     => $remarks ?? $pr->remarks,
        ]);

        return $pr->load(['items.product', 'items.unit', 'items.attribute', 'sourceLocation', 'sourceStore', 'targetLocation', 'targetStore']);
    }

    public function reject(PurchaseRequest $pr, string $reason): PurchaseRequest
    {
        if ($pr->status !== PurchaseRequestStatus::Submitted) {
            abort(422, 'Only submitted purchase requests can be rejected.');
        }

        $pr->update([
            'status'           => PurchaseRequestStatus::Rejected,
            'rejection_reason' => $reason,
        ]);

        return $pr->load(['items.product', 'items.unit', 'items.attribute', 'sourceLocation', 'sourceStore', 'targetLocation', 'targetStore']);
    }

    public function cancel(PurchaseRequest $pr): PurchaseRequest
    {
        if (!in_array($pr->status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::Submitted])) {
            abort(422, 'Only draft or submitted purchase requests can be cancelled.');
        }

        $pr->update(['status' => PurchaseRequestStatus::Cancelled]);

        return $pr->load(['items.product', 'items.unit', 'items.attribute', 'sourceLocation', 'sourceStore', 'targetLocation', 'targetStore']);
    }

    public function delete(PurchaseRequest $pr): void
    {
        if ($pr->status !== PurchaseRequestStatus::Draft) {
            abort(422, 'Only draft purchase requests can be deleted.');
        }

        $pr->delete();
    }

    /** Preview the next reference number (non-locking, for display only) */
    public function nextReferenceNo(): string
    {
        $prefix = 'Ref-';

        $last = PurchaseRequest::withTrashed()
            ->where('reference_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('reference_no');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Atomically generate the next reference number (must be called inside a DB transaction) */
    private function generateReferenceNo(): string
    {
        $prefix = 'Ref-';

        $last = PurchaseRequest::withTrashed()
            ->where('reference_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('reference_no');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** Generate next PR number in format PR-YYYY-NNNN */
    private function generatePrNo(): string
    {
        $year = now()->year;
        $prefix = "PR-{$year}-";

        $last = PurchaseRequest::withTrashed()
            ->where('pr_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('pr_no');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<array{product_id:int, unit_id:?int, attribute_id:?int, quantity:float, estimated_unit_price:?float, remarks:?string}> $items
     */
    private function syncItems(PurchaseRequest $pr, array $items): void
    {
        $pr->items()->delete();

        $rows = collect($items)
            ->filter(fn (array $row) => !empty($row['product_id']) && ($row['quantity'] ?? 0) > 0)
            ->map(fn (array $row) => [
                'pr_id'                => $pr->id,
                'product_id'           => (int) $row['product_id'],
                'unit_id'              => !empty($row['unit_id']) ? (int) $row['unit_id'] : null,
                'attribute_id'         => !empty($row['attribute_id']) ? (int) $row['attribute_id'] : null,
                'quantity'             => (float) $row['quantity'],
                'estimated_unit_price' => isset($row['estimated_unit_price']) ? (float) $row['estimated_unit_price'] : null,
                'remarks'              => $row['remarks'] ?? null,
                'created_at'           => now(),
                'updated_at'           => now(),
            ])
            ->values()
            ->all();

        if (!empty($rows)) {
            PurchaseRequestItem::insert($rows);
        }
    }
}
