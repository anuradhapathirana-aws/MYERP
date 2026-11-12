<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\StoreSupplierReturnRequest;
use Modules\Inventory\Http\Requests\UpdateSupplierReturnRequest;

final class SupplierReturnData
{
    /**
     * @param array<array{grn_item_id:int, quantity:?float, reason:string, remarks:?string, pieces:?array<array{piece_id:int, quantity:float}>}> $items
     */
    public function __construct(
        public readonly string  $returnDate,
        public readonly int     $supplierId,
        public readonly int     $grnId,
        public readonly int     $storeId,
        public readonly ?string $remarks,
        public readonly array   $items,
    ) {}

    public static function fromRequest(
        StoreSupplierReturnRequest|UpdateSupplierReturnRequest $request,
    ): self {
        return new self(
            returnDate: $request->validated('return_date'),
            supplierId: (int) $request->validated('supplier_id'),
            grnId:      (int) $request->validated('grn_id'),
            storeId:    (int) $request->validated('store_id'),
            remarks:    $request->validated('remarks'),
            items:      (array) $request->validated('items'),
        );
    }
}
