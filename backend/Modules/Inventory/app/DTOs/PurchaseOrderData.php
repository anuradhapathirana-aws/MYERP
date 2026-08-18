<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\StorePurchaseOrderRequest;
use Modules\Inventory\Http\Requests\UpdatePurchaseOrderRequest;

final class PurchaseOrderData
{
    /** @param array<array{product_id:int, unit_id:?int, pr_item_id:?int, quantity_ordered:float, unit_price:float, discount:float, tax:float, remarks:?string}> $items */
    public function __construct(
        public readonly string  $poNo,
        public readonly ?int    $prId,
        public readonly int     $supplierId,
        public readonly int     $storeId,
        public readonly ?int    $locationId,
        public readonly string  $orderDate,
        public readonly ?string $expectedDeliveryDate,
        public readonly ?string $referenceNo,
        public readonly ?string $location,
        public readonly ?string $paymentTerms,
        public readonly string  $contactPersonName,
        public readonly string  $contactPersonPhone,
        public readonly bool    $isConsignment,
        public readonly string  $billingAddress,
        public readonly ?string $shippingAddress,
        public readonly ?string $remarks,
        public readonly array   $items,
        public readonly ?string $status = null,
    ) {}

    public static function fromRequest(
        StorePurchaseOrderRequest|UpdatePurchaseOrderRequest $request,
    ): self {
        return new self(
            poNo:                 $request->validated('po_no'),
            prId:                 $request->validated('pr_id') !== null
                                      ? (int) $request->validated('pr_id')
                                      : null,
            supplierId:           (int) $request->validated('supplier_id'),
            storeId:              (int) $request->validated('store_id'),
            locationId:           $request->validated('location_id') !== null
                                      ? (int) $request->validated('location_id')
                                      : null,
            orderDate:            $request->validated('order_date'),
            expectedDeliveryDate: $request->validated('expected_delivery_date'),
            referenceNo:          $request->validated('reference_no'),
            location:             $request->validated('location'),
            paymentTerms:         $request->validated('payment_terms'),
            contactPersonName:    $request->validated('contact_person_name'),
            contactPersonPhone:   $request->validated('contact_person_phone'),
            isConsignment:        (bool) ($request->validated('is_consignment') ?? false),
            billingAddress:       $request->validated('billing_address'),
            shippingAddress:      $request->validated('shipping_address'),
            remarks:              $request->validated('remarks'),
            items:                (array) ($request->validated('items') ?? []),
            status:               $request->validated('status'),
        );
    }
}
