<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

class UpdateProformaInvoiceRequest extends StoreProformaInvoiceRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();

        // The customer cannot change once the proforma exists — ignored by the service.
        $rules['customer_id'] = ['nullable', 'integer'];

        return $rules;
    }
}
