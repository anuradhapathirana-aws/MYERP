<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\StorePurchaseRequestRequest;
use Modules\Inventory\Http\Requests\UpdatePurchaseRequestRequest;

final class PurchaseRequestData
{
    /** @param array<array{product_id:int, unit_id:?int, quantity:float, estimated_unit_price:?float, remarks:?string}> $items */
    public function __construct(
        public readonly string  $requestDate,
        public readonly ?string $referenceNo,
        public readonly ?string $purpose,
        public readonly ?int    $sourceLocationId,
        public readonly ?int    $sourceStoreId,
        public readonly ?int    $targetLocationId,
        public readonly ?int    $targetStoreId,
        public readonly ?int    $customerId,
        public readonly ?string $requiredDate,
        public readonly ?string $transportMode,
        public readonly ?string $remarks,
        public readonly bool    $submitForApproval,
        public readonly string  $status,
        public readonly array   $items,
    ) {}

    public static function fromRequest(
        StorePurchaseRequestRequest|UpdatePurchaseRequestRequest $request,
    ): self {
        return new self(
            requestDate:       $request->validated('request_date'),
            referenceNo:       $request->validated('reference_no'),
            purpose:           $request->validated('purpose'),
            sourceLocationId:  $request->validated('source_location_id') !== null
                                   ? (int) $request->validated('source_location_id')
                                   : null,
            sourceStoreId:     $request->validated('source_store_id') !== null
                                   ? (int) $request->validated('source_store_id')
                                   : null,
            targetLocationId:  $request->validated('target_location_id') !== null
                                   ? (int) $request->validated('target_location_id')
                                   : null,
            targetStoreId:     $request->validated('target_store_id') !== null
                                   ? (int) $request->validated('target_store_id')
                                   : null,
            customerId:        $request->validated('customer_id') !== null
                                   ? (int) $request->validated('customer_id')
                                   : null,
            requiredDate:      $request->validated('required_date'),
            transportMode:     $request->validated('transport_mode'),
            remarks:           $request->validated('remarks'),
            submitForApproval: (bool) ($request->validated('submit_for_approval') ?? false),
            status:            $request->validated('status') ?? 'approved',
            items:             (array) ($request->validated('items') ?? []),
        );
    }
}
