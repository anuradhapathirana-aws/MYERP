<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\StoreStockReconciliationRequest;
use Modules\Inventory\Http\Requests\UpdateStockReconciliationRequest;

final class StockReconciliationData
{
    /** @param array<int, array{grn_item_piece_id:int, new_weight:float}> $pieces */
    public function __construct(
        public readonly int     $productId,
        public readonly ?int    $attributeId,
        public readonly int     $storeId,
        public readonly int     $locationId,
        public readonly ?int    $batchId,
        public readonly ?int    $unitId,
        public readonly ?float  $countedQty,
        public readonly ?string $sourceType,
        public readonly ?int    $sourceId,
        public readonly bool    $alsoAdjustPo,
        public readonly string  $reason,
        public readonly ?string $remarks,
        public readonly array   $pieces,
    ) {}

    public static function fromRequest(
        StoreStockReconciliationRequest|UpdateStockReconciliationRequest $request,
    ): self {
        return new self(
            productId:    (int) $request->validated('product_id'),
            attributeId:  $request->validated('attribute_id') !== null ? (int) $request->validated('attribute_id') : null,
            storeId:      (int) $request->validated('store_id'),
            locationId:   (int) $request->validated('location_id'),
            batchId:      $request->validated('batch_id') !== null ? (int) $request->validated('batch_id') : null,
            unitId:       $request->validated('unit_id') !== null ? (int) $request->validated('unit_id') : null,
            countedQty:   $request->validated('counted_qty') !== null ? (float) $request->validated('counted_qty') : null,
            sourceType:   $request->validated('source_type'),
            sourceId:     $request->validated('source_id') !== null ? (int) $request->validated('source_id') : null,
            alsoAdjustPo: (bool) ($request->validated('also_adjust_po') ?? false),
            reason:       $request->validated('reason'),
            remarks:      $request->validated('remarks'),
            pieces:       (array) ($request->validated('pieces') ?? []),
        );
    }
}
