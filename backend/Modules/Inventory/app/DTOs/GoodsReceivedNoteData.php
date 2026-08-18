<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\StoreGoodsReceivedNoteRequest;
use Modules\Inventory\Http\Requests\UpdateGoodsReceivedNoteRequest;

final class GoodsReceivedNoteData
{
    /** @param array<array{po_item_id:int, product_id:int, unit_id:?int, quantity_received:float, rolls:?array, unit_price:float, batch_no:?string, expiry_date:?string}> $items */
    public function __construct(
        public readonly ?int    $poId,
        public readonly ?int    $supplierId,
        public readonly string  $grnDate,
        public readonly ?string $transactionDate,
        public readonly ?string $referenceNo,
        public readonly string  $shippingCode,
        public readonly ?int    $storeId,
        public readonly ?int    $locationId,
        public readonly ?string $remarks,
        public readonly ?string $paymentTerms,
        public readonly ?array  $attachments,
        public readonly array   $items,
    ) {}

    public static function fromRequest(
        StoreGoodsReceivedNoteRequest|UpdateGoodsReceivedNoteRequest $request,
    ): self {
        return new self(
            poId:            $request->validated('po_id') !== null ? (int) $request->validated('po_id') : null,
            supplierId:      $request->validated('supplier_id') !== null ? (int) $request->validated('supplier_id') : null,
            grnDate:         $request->validated('grn_date'),
            transactionDate: $request->validated('transaction_date'),
            referenceNo:     $request->validated('reference_no'),
            shippingCode:    $request->validated('shipping_code'),
            storeId:         $request->validated('store_id') !== null ? (int) $request->validated('store_id') : null,
            locationId:      $request->validated('location_id') !== null ? (int) $request->validated('location_id') : null,
            remarks:         $request->validated('remarks'),
            paymentTerms:    $request->validated('payment_terms'),
            attachments:     $request->validated('attachments'),
            items:           (array) ($request->validated('items') ?? []),
        );
    }
}
