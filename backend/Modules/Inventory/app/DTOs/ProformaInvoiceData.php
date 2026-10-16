<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\StoreProformaInvoiceRequest;
use Modules\Inventory\Http\Requests\UpdateProformaInvoiceRequest;

/**
 * A proforma raised before any sales order: the customer and typed lines
 * (any product, in stock or not).
 */
final class ProformaInvoiceData
{
    /** @param array<int, array{product_id:int, attribute_id:?int, unit_id:?int, quantity:float, unit_price:float, discount:?float, tax:?float, remarks:?string}> $items */
    public function __construct(
        public readonly ?int    $customerId,
        public readonly ?int    $companyId,
        public readonly string  $proformaDate,
        public readonly ?string $validUntil,
        public readonly ?string $paymentTerms,
        public readonly ?string $invoiceType, // 'tax' | 'non_tax'; null = default (create: by customer TIN, update: keep)
        public readonly ?float  $transportCharge,
        public readonly ?string $deliveryAddress,
        public readonly ?string $remarks,
        public readonly array   $items,
    ) {}

    public static function fromRequest(
        StoreProformaInvoiceRequest|UpdateProformaInvoiceRequest $request,
    ): self {
        return new self(
            customerId:      $request->validated('customer_id') !== null ? (int) $request->validated('customer_id') : null,
            companyId:       $request->validated('company_id') !== null ? (int) $request->validated('company_id') : null,
            proformaDate:    $request->validated('proforma_date'),
            validUntil:      $request->validated('valid_until'),
            paymentTerms:    $request->validated('payment_terms'),
            invoiceType:     $request->validated('invoice_type'),
            transportCharge: $request->validated('transport_charge') !== null ? (float) $request->validated('transport_charge') : null,
            deliveryAddress: $request->validated('delivery_address'),
            remarks:         $request->validated('remarks'),
            items:           (array) ($request->validated('items') ?? []),
        );
    }
}
