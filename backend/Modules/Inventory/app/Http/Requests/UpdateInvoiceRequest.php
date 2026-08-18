<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

class UpdateInvoiceRequest extends StoreInvoiceRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();

        // The source document cannot change on update — both become optional
        // and are ignored by the service.
        $rules['so_id'] = ['nullable', 'integer'];
        $rules['do_id'] = ['nullable', 'integer'];

        return $rules;
    }
}
