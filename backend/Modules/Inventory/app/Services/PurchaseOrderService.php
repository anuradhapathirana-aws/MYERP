<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\PurchaseOrderData;
use Modules\Inventory\Enums\PurchaseOrderStatus;
use Modules\Inventory\Enums\PurchaseRequestStatus;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\PurchaseOrderItem;
use Modules\Inventory\Models\PurchaseRequest;

class PurchaseOrderService
{
    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = PurchaseOrder::with([
                'supplier',
                'store',
                'location',
                'purchaseRequest.sourceStore',
                'purchaseRequest.sourceLocation',
            ])
            ->orderByDesc('order_date')
            ->orderByDesc('id');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term): void {
                $q->where('po_no', 'like', $term)
                  ->orWhereHas('supplier', fn ($s) => $s->where('supplier_name', 'like', $term));
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }

        if (!empty($filters['store_id'])) {
            $query->where('store_id', (int) $filters['store_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('order_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('order_date', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    public function find(int $id): PurchaseOrder
    {
        return PurchaseOrder::with([
            'items.product',
            'items.unit',
            'items.attribute',
            'items.prItem',
            'supplier',
            'store',
            'location',
            'purchaseRequest',
            'goodsReceivedNotes',
        ])->findOrFail($id);
    }

    /** Load PR items into PO draft (pre-populate from an approved PR) */
    public function loadFromPR(int $prId): array
    {
        $pr = PurchaseRequest::with(['items.product', 'items.unit', 'items.attribute'])->findOrFail($prId);

        if ($pr->status !== PurchaseRequestStatus::Approved) {
            abort(422, 'Purchase requests must be approved before creating a PO.');
        }

        return [
            'pr'    => $pr,
            'items' => $pr->items->map(fn ($item) => [
                'pr_item_id'     => $item->id,
                'product_id'     => $item->product_id,
                'unit_id'        => $item->unit_id,
                'attribute_id'   => $item->attribute_id,
                'quantity_ordered' => (float) $item->quantity,
                'unit_price'     => (float) ($item->estimated_unit_price ?? 0),
                'product'        => [
                    'id'           => $item->product->id,
                    'name'         => $item->product->name,
                    'product_code' => $item->product->product_code,
                ],
            ])->values()->all(),
        ];
    }

    public function create(PurchaseOrderData $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data): PurchaseOrder {
            $po = PurchaseOrder::create([
                'po_no'                  => $data->poNo,
                'pr_id'                  => $data->prId,
                'supplier_id'            => $data->supplierId,
                'store_id'               => $data->storeId,
                'location_id'            => $data->locationId,
                'order_date'             => $data->orderDate,
                'expected_delivery_date' => $data->expectedDeliveryDate,
                'reference_no'           => $data->referenceNo,
                'location'               => $data->location,
                'payment_terms'          => $data->paymentTerms,
                'contact_person_name'    => $data->contactPersonName,
                'contact_person_phone'   => $data->contactPersonPhone,
                'is_consignment'         => $data->isConsignment,
                'billing_address'        => $data->billingAddress,
                'shipping_address'       => $data->shippingAddress,
                'status'                 => $data->status
                                               ? PurchaseOrderStatus::from($data->status)
                                               : PurchaseOrderStatus::Draft,
                'remarks'                => $data->remarks,
                'created_by'             => Auth::id(),
                'subtotal'               => 0,
                'grand_total'            => 0,
            ]);

            $this->syncItems($po, $data->items);
            $this->recalculateTotals($po);

            // Mark linked PR as completed
            if ($data->prId) {
                PurchaseRequest::where('id', $data->prId)
                    ->where('status', PurchaseRequestStatus::Approved->value)
                    ->update(['status' => PurchaseRequestStatus::Completed->value]);
            }

            return $po->load(['items.product', 'items.unit', 'items.attribute', 'supplier', 'store', 'purchaseRequest']);
        });
    }

    public function update(PurchaseOrder $po, PurchaseOrderData $data): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $data): PurchaseOrder {
            // Apply status transition first if a new status is provided and differs from current
            if ($data->status !== null) {
                $newStatus = PurchaseOrderStatus::from($data->status);
                if ($newStatus !== $po->status) {
                    $this->updateStatus($po, $data->status);
                    $po->refresh();
                }
            }

            // Update form fields only while the PO is still in an editable state
            if (in_array($po->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Sent])) {
                $po->update([
                    'po_no'                  => $data->poNo,
                    'supplier_id'            => $data->supplierId,
                    'store_id'               => $data->storeId,
                    'location_id'            => $data->locationId,
                    'order_date'             => $data->orderDate,
                    'expected_delivery_date' => $data->expectedDeliveryDate,
                    'reference_no'           => $data->referenceNo,
                    'location'               => $data->location,
                    'payment_terms'          => $data->paymentTerms,
                    'contact_person_name'    => $data->contactPersonName,
                    'contact_person_phone'   => $data->contactPersonPhone,
                    'is_consignment'         => $data->isConsignment,
                    'billing_address'        => $data->billingAddress,
                    'shipping_address'       => $data->shippingAddress,
                    'remarks'                => $data->remarks,
                ]);

                $this->syncItems($po, $data->items);
                $this->recalculateTotals($po);
            }

            return $po->load(['items.product', 'items.unit', 'items.attribute', 'supplier', 'store', 'purchaseRequest']);
        });
    }

    public function updateStatus(PurchaseOrder $po, string $status): PurchaseOrder
    {
        $newStatus = PurchaseOrderStatus::from($status);

        $allowedTransitions = [
            PurchaseOrderStatus::Draft->value            => [PurchaseOrderStatus::Sent, PurchaseOrderStatus::Confirmed, PurchaseOrderStatus::Cancelled],
            PurchaseOrderStatus::Sent->value             => [PurchaseOrderStatus::Confirmed, PurchaseOrderStatus::PartiallyReceived, PurchaseOrderStatus::Cancelled],
            PurchaseOrderStatus::Confirmed->value        => [PurchaseOrderStatus::PartiallyReceived, PurchaseOrderStatus::Completed, PurchaseOrderStatus::Cancelled],
            PurchaseOrderStatus::PartiallyReceived->value => [PurchaseOrderStatus::Completed, PurchaseOrderStatus::Cancelled],
        ];

        $allowed = $allowedTransitions[$po->status->value] ?? [];
        if (!in_array($newStatus, $allowed)) {
            abort(422, "Cannot transition PO from {$po->status->label()} to {$newStatus->label()}.");
        }

        $po->update(['status' => $newStatus]);

        return $po;
    }

    public function nextPoNo(): string
    {
        $year   = now()->year;
        $prefix = "PO-{$year}-";

        $last = PurchaseOrder::withTrashed()
            ->where('po_no', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('po_no');

        $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function delete(PurchaseOrder $po): void
    {
        if ($po->status !== PurchaseOrderStatus::Draft) {
            abort(422, 'Only draft purchase orders can be deleted.');
        }

        $po->delete();
    }

    /**
     * @param array<array{product_id:int, unit_id:?int, pr_item_id:?int, quantity_ordered:float, unit_price:float, discount:float, tax:float, remarks:?string}> $items
     */
    private function syncItems(PurchaseOrder $po, array $items): void
    {
        $po->items()->delete();

        $rows = collect($items)
            ->filter(fn (array $row) => !empty($row['product_id']) && ($row['quantity_ordered'] ?? 0) > 0)
            ->map(function (array $row) use ($po): array {
                $qty       = (float) $row['quantity_ordered'];
                $price     = (float) ($row['unit_price'] ?? 0);
                $discount  = (float) ($row['discount'] ?? 0);
                $tax       = (float) ($row['tax'] ?? 0);
                $gross     = $qty * $price;
                $lineTotal = $gross - ($gross * $discount / 100) + ($gross * $tax / 100);

                return [
                    'po_id'             => $po->id,
                    'product_id'        => (int) $row['product_id'],
                    'unit_id'           => !empty($row['unit_id']) ? (int) $row['unit_id'] : null,
                    'attribute_id'      => !empty($row['attribute_id']) ? (int) $row['attribute_id'] : null,
                    'pr_item_id'        => !empty($row['pr_item_id']) ? (int) $row['pr_item_id'] : null,
                    'quantity_ordered'  => $qty,
                    'quantity_received' => 0,
                    'unit_price'        => $price,
                    'discount'          => $discount,
                    'tax'               => $tax,
                    'line_total'        => $lineTotal,
                    'remarks'           => $row['remarks'] ?? null,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ];
            })
            ->values()
            ->all();

        if (!empty($rows)) {
            PurchaseOrderItem::insert($rows);
        }
    }

    private function recalculateTotals(PurchaseOrder $po): void
    {
        $po->refresh();
        $subtotal   = $po->items()->sum(DB::raw('quantity_ordered * unit_price'));
        $grandTotal = $po->items()->sum(DB::raw('line_total'));

        $po->update([
            'subtotal'    => $subtotal,
            'grand_total' => $grandTotal,
        ]);
    }
}
