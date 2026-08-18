<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\StoreSalesOrderRequest;
use Modules\Inventory\Http\Requests\UpdateSalesOrderRequest;

final class SalesOrderData
{
    /** @param array<array{product_id:int, unit_id:?int, attribute_id:?int, quantity:?float, unit_price:float, discount:float, tax:float, remarks:?string, piece_codes:?array<string>, piece_takes:?array<string, float>}> $items */
    public function __construct(
        public readonly ?string $referenceNo,
        public readonly int     $customerId,
        public readonly int     $salesPersonId,
        public readonly ?int    $orderTakenBy,
        public readonly ?string $customerType,
        public readonly string  $orderDate,
        public readonly ?string $expectedDate,
        public readonly ?string $transactionDate,
        public readonly ?string $orderSource,
        public readonly ?string $deliveryAddress,
        public readonly float   $transportCharge,
        public readonly ?string $remarks,
        public readonly array   $items,
        public readonly ?string $status = null,
    ) {}

    public static function fromRequest(
        StoreSalesOrderRequest|UpdateSalesOrderRequest $request,
    ): self {
        return new self(
            referenceNo:     $request->validated('reference_no'),
            customerId:      (int) $request->validated('customer_id'),
            salesPersonId:   (int) $request->validated('sales_person_id'),
            orderTakenBy:    $request->validated('order_taken_by') !== null
                                 ? (int) $request->validated('order_taken_by')
                                 : null,
            customerType:    $request->validated('customer_type'),
            orderDate:       $request->validated('order_date'),
            expectedDate:    $request->validated('expected_date'),
            transactionDate: $request->validated('transaction_date'),
            orderSource:     $request->validated('order_source'),
            deliveryAddress: $request->validated('delivery_address'),
            transportCharge: (float) ($request->validated('transport_charge') ?? 0),
            remarks:         $request->validated('remarks'),
            items:           (array) ($request->validated('items') ?? []),
            status:          $request->validated('status'),
        );
    }
}
