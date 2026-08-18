<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Enums\PaymentMode;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Invoices bill exactly one confirmed DO. Direct-SO (advance) billing
            // is retired — so_id is never accepted from input.
            'do_id'            => ['required', 'integer', 'exists:inv_delivery_orders,id'],
            'company_id'       => ['nullable', 'integer', 'exists:inv_companies,id'],
            'invoice_date'     => ['required', 'date'],
            'due_date'         => ['nullable', 'date', 'after_or_equal:invoice_date'],
            // tax: before-tax prices + VAT per line · non_tax: after-tax prices, no VAT
            'invoice_type'     => ['nullable', 'string', 'in:tax,non_tax'],
            'transport_charge' => ['nullable', 'numeric', 'min:0'],
            'delivery_address' => ['nullable', 'string', 'max:2000'],
            'remarks'          => ['nullable', 'string', 'max:2000'],
            'mode_of_payment'  => ['nullable', Rule::enum(PaymentMode::class)],

            // Draft-stage re-pricing only — quantities always come from the DO/SO
            'items'              => ['nullable', 'array'],
            'items.*.so_item_id' => ['required', 'integer'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.remarks'    => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'so_id'            => 'sales order',
            'do_id'            => 'delivery order',
            'company_id'       => 'company',
            'invoice_date'     => 'invoice date',
            'due_date'         => 'due date',
            'transport_charge' => 'transport charge',
            'mode_of_payment'  => 'mode of payment',
        ];
    }
}
