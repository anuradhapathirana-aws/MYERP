<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\StoreProductRequest;
use Modules\Inventory\Http\Requests\UpdateProductRequest;

final class ProductData
{
    /**
     * @param array<int>                            $supplierIds
     * @param array<array<string, mixed>>           $costDetails
     * @param array<array{attribute_type_id:int, attribute_id:int}> $productAttributes
     */
    public function __construct(
        public readonly string  $name,
        public readonly string  $displayName,
        public readonly string  $productType,
        public readonly ?string $referenceNo,
        public readonly ?string $ean13,
        public readonly ?string $description,
        public readonly int     $categoryId,
        public readonly ?int    $locationId,
        public readonly int     $baseUnitTypeId,
        public readonly ?float  $reorderLevel,
        public readonly ?float  $reorderQty,
        public readonly ?int    $reorderPeriod,
        public readonly ?string $stockReleasingMethod,
        public readonly ?string $trackingType,
        public readonly bool    $lockPurchase,
        public readonly bool    $allowComplementaryItems,
        public readonly bool    $freeIssue,
        public readonly bool    $allowMinus,
        public readonly bool    $notAllowDirectSale,
        public readonly bool    $nonReturnable,
        public readonly bool    $isEmpty,
        public readonly bool    $serviceCharge,
        public readonly bool    $loyalty,
        public readonly bool    $isBatch,
        public readonly bool    $isSerial,
        public readonly array   $supplierIds,
        public readonly array   $costDetails,
        public readonly array   $productAttributes,
        public readonly array   $locationStores,
    ) {}

    public static function fromRequest(StoreProductRequest|UpdateProductRequest $request): self
    {
        return new self(
            name:                    $request->validated('name'),
            displayName:             $request->validated('display_name'),
            productType:             $request->validated('product_type'),
            referenceNo:             $request->validated('reference_no'),
            ean13:                   $request->validated('ean_13'),
            description:             $request->validated('description'),
            categoryId:              (int) $request->validated('category_id'),
            locationId:              $request->validated('location_id') !== null
                                         ? (int) $request->validated('location_id')
                                         : null,
            baseUnitTypeId:          (int) $request->validated('base_unit_type_id'),
            reorderLevel:            $request->validated('reorder_level') !== null
                                         ? (float) $request->validated('reorder_level')
                                         : null,
            reorderQty:              $request->validated('reorder_qty') !== null
                                         ? (float) $request->validated('reorder_qty')
                                         : null,
            reorderPeriod:           $request->validated('reorder_period') !== null
                                         ? (int) $request->validated('reorder_period')
                                         : null,
            stockReleasingMethod:    $request->validated('stock_releasing_method'),
            trackingType:            $request->validated('tracking_type'),
            lockPurchase:            (bool) ($request->validated('lock_purchase')            ?? false),
            allowComplementaryItems: (bool) ($request->validated('allow_complimentary_items') ?? false),
            freeIssue:               (bool) ($request->validated('free_issue')               ?? false),
            allowMinus:              (bool) ($request->validated('allow_minus')              ?? false),
            notAllowDirectSale:      (bool) ($request->validated('not_allow_direct_sale')    ?? false),
            nonReturnable:           (bool) ($request->validated('non_returnable')           ?? false),
            isEmpty:                 (bool) ($request->validated('is_empty')                 ?? false),
            serviceCharge:           (bool) ($request->validated('service_charge')           ?? false),
            loyalty:                 (bool) ($request->validated('loyalty')                  ?? false),
            isBatch:                 (bool) ($request->validated('is_batch')                 ?? false),
            isSerial:                (bool) ($request->validated('is_serial')                ?? false),
            supplierIds:             (array) ($request->validated('supplier_ids')             ?? []),
            costDetails:             (array) ($request->validated('cost_details')             ?? []),
            productAttributes:       (array) ($request->validated('product_attributes')      ?? []),
            locationStores:          (array) ($request->validated('location_stores')          ?? []),
        );
    }
}
