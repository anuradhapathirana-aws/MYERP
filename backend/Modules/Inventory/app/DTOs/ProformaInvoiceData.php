<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\StoreProformaInvoiceRequest;
use Modules\Inventory\Http\Requests\UpdateProformaInvoiceRequest;

/**
 * Header-only input — proforma lines are always built from the sales order
 * (prices locked to the SO), so no line data is accepted from the form.
 */
final class ProformaInvoiceData
{
    public function __construct(
        public readonly ?int    $soId,
        public readonly ?int    $companyId,
        public readonly string  $proformaDate,
        public readonly ?string $validUntil,
        public readonly ?string $paymentTerms,
        public readonly ?string $invoiceType, // 'tax' | 'non_tax'; null = default (create: by customer TIN, update: keep)
        public readonly ?string $deliveryAddress,
        public readonly ?string $remarks,
    ) {}

    public static function fromRequest(
        StoreProformaInvoiceRequest|UpdateProformaInvoiceRequest $request,
    ): self {
        return new self(
            soId:            $request->validated('so_id') !== null ? (int) $request->validated('so_id') : null,
            companyId:       $request->validated('company_id') !== null ? (int) $request->validated('company_id') : null,
            proformaDate:    $request->validated('proforma_date'),
            validUntil:      $request->validated('valid_until'),
            paymentTerms:    $request->validated('payment_terms'),
            invoiceType:     $request->validated('invoice_type'),
            deliveryAddress: $request->validated('delivery_address'),
            remarks:         $request->validated('remarks'),
        );
    }
}
