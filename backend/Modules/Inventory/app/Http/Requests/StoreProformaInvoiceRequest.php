<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ResolvesLineVariants;

class StoreProformaInvoiceRequest extends FormRequest
{
    use ResolvesLineVariants;

    protected function prepareForValidation(): void
    {
        $this->prepareLineVariants();
    }

    /** A product with variants is quoted by exact variant (Red / Floral / M). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateLineVariants($v));
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Raised before any sales order — the customer and typed lines (any product,
            // in stock or not). proforma_no is generated and never accepted from input.
            'customer_id'      => ['required', 'integer', 'exists:inv_customer_masters,id'],
            'company_id'       => ['nullable', 'integer', 'exists:inv_companies,id'],
            'proforma_date'    => ['required', 'date'],
            'valid_until'      => ['nullable', 'date', 'after_or_equal:proforma_date'],
            'payment_terms'    => ['nullable', 'string', 'max:2000'],
            'invoice_type'     => ['nullable', 'string', 'in:tax,non_tax'],
            'transport_charge' => ['nullable', 'numeric', 'min:0'],
            'delivery_address' => ['nullable', 'string', 'max:2000'],
            'remarks'          => ['nullable', 'string', 'max:2000'],

            'items'                => ['required', 'array', 'min:1'],
            'items.*.product_id'   => ['required', 'integer', 'exists:inv_products,id'],
            'items.*.attribute_id' => ['nullable', 'integer', 'exists:inv_attributes,id'],
            'items.*.variant_id'   => ['nullable', 'integer'],
            'items.*.unit_id'      => ['nullable', 'integer', 'exists:inv_unit_types,id'],
            'items.*.quantity'     => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price'   => ['required', 'numeric', 'min:0'],
            'items.*.discount'     => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax'          => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.remarks'      => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'customer_id'        => 'customer',
            'company_id'         => 'company',
            'proforma_date'      => 'proforma date',
            'valid_until'        => 'valid until',
            'payment_terms'      => 'payment terms',
            'transport_charge'   => 'transport charge',
            'items.*.product_id' => 'product',
            'items.*.quantity'   => 'quantity',
            'items.*.unit_price' => 'unit price',
        ];
    }
}
