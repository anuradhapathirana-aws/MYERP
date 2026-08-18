<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\StoreInvoiceRequest;
use Modules\Inventory\Http\Requests\UpdateInvoiceRequest;

final class InvoiceData
{
    /** @param array<array{so_item_id:int, unit_price:?float, discount:?float, tax:?float, remarks:?string}> $items */
    public function __construct(
        public readonly ?int    $soId,
        public readonly ?int    $doId,
        public readonly ?int    $companyId,
        public readonly string  $invoiceDate,
        public readonly ?string $dueDate,
        public readonly ?string $invoiceType, // 'tax' | 'non_tax'; null = default (create: tax, update: keep)
        public readonly ?float  $transportCharge,
        public readonly ?string $deliveryAddress,
        public readonly ?string $remarks,
        public readonly ?string $modeOfPayment,
        public readonly array   $items,
    ) {}

    public static function fromRequest(
        StoreInvoiceRequest|UpdateInvoiceRequest $request,
    ): self {
        return new self(
            soId:            $request->validated('so_id') !== null
                                 ? (int) $request->validated('so_id')
                                 : null,
            doId:            $request->validated('do_id') !== null
                                 ? (int) $request->validated('do_id')
                                 : null,
            companyId:       $request->validated('company_id') !== null
                                 ? (int) $request->validated('company_id')
                                 : null,
            invoiceDate:     $request->validated('invoice_date'),
            dueDate:         $request->validated('due_date'),
            invoiceType:     $request->validated('invoice_type'),
            transportCharge: $request->validated('transport_charge') !== null
                                 ? (float) $request->validated('transport_charge')
                                 : null,
            deliveryAddress: $request->validated('delivery_address'),
            remarks:         $request->validated('remarks'),
            modeOfPayment:   $request->validated('mode_of_payment'),
            items:           (array) ($request->validated('items') ?? []),
        );
    }
}
