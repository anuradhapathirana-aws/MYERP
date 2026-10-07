<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\StoreCustomerReturnRequest;
use Modules\Inventory\Http\Requests\UpdateCustomerReturnRequest;

final class CustomerReturnData
{
    /**
     * @param array<array{invoice_item_id:int, quantity:?float, reason:string, condition:string, store_id:?int, remarks:?string, pieces:?array<array{do_piece_id:int, quantity:float}>}> $items
     */
    public function __construct(
        public readonly string  $returnDate,
        public readonly int     $customerId,
        public readonly int     $invoiceId,
        public readonly int     $storeId,
        public readonly ?string $remarks,
        public readonly array   $items,
    ) {}

    public static function fromRequest(
        StoreCustomerReturnRequest|UpdateCustomerReturnRequest $request,
    ): self {
        return new self(
            returnDate: $request->validated('return_date'),
            customerId: (int) $request->validated('customer_id'),
            invoiceId:  (int) $request->validated('invoice_id'),
            storeId:    (int) $request->validated('store_id'),
            remarks:    $request->validated('remarks'),
            items:      (array) $request->validated('items'),
        );
    }
}
