<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

class UpdateProformaInvoiceRequest extends StoreProformaInvoiceRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();

        // The source sales order cannot change on update — ignored by the service.
        $rules['so_id'] = ['nullable', 'integer'];

        return $rules;
    }
}
