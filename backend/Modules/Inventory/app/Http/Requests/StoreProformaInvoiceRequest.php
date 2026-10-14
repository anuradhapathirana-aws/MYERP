<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProformaInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Raised from a confirmed sales order; lines and prices always come from
            // the SO, and proforma_no is generated — neither is accepted from input.
            'so_id'            => ['required', 'integer', 'exists:inv_sales_orders,id'],
            'company_id'       => ['nullable', 'integer', 'exists:inv_companies,id'],
            'proforma_date'    => ['required', 'date'],
            'valid_until'      => ['nullable', 'date', 'after_or_equal:proforma_date'],
            'payment_terms'    => ['nullable', 'string', 'max:2000'],
            'invoice_type'     => ['nullable', 'string', 'in:tax,non_tax'],
            'delivery_address' => ['nullable', 'string', 'max:2000'],
            'remarks'          => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'so_id'         => 'sales order',
            'company_id'    => 'company',
            'proforma_date' => 'proforma date',
            'valid_until'   => 'valid until',
            'payment_terms' => 'payment terms',
        ];
    }
}
