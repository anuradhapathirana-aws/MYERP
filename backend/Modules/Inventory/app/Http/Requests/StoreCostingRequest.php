<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCostingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'supplier_id'      => ['required', 'integer', 'min:1'],
            'costing_type'     => ['required', 'string', 'in:fob,cif'],
            'grn_ids'          => ['required', 'array', 'min:1'],
            'grn_ids.*'        => ['required', 'integer', 'min:1'],
            'bill_of_lading'   => ['nullable', 'string', 'max:100'],
            'expected_date'    => ['nullable', 'date'],
            'transaction_date' => ['nullable', 'date'],
            'note'             => ['nullable', 'string'],

            // Header defaults for the per-line build-up: every line computes
            //   before_tax = fob/cif + Σ expenses + margin + SSCL(% of that whole sum)
            //   after_tax  = before_tax + VAT%
            // sscl_pct / vat_pct are the defaults a line uses unless it overrides them.
            'apply_sscl' => ['nullable', 'boolean'],
            'sscl_pct'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'apply_vat'  => ['nullable', 'boolean'],
            'vat_pct'    => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Per-product costing inputs. ALL monetary amounts are quoted PER THE
            // PRODUCT'S BASE (stocking) UNIT — the unit the customer is invoiced in,
            // never the GRN's receiving unit.
            'items'                                => ['nullable', 'array'],
            'items.*.grn_item_id'                  => ['required_with:items', 'integer', 'min:1'],
            'items.*.margin_amount_base'           => ['nullable', 'numeric', 'min:0'],
            'items.*.sscl_pct'                     => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.vat_pct'                      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.expenses'                     => ['nullable', 'array'],
            'items.*.expenses.*.expense_type_id'   => ['required', 'integer', 'min:1'],
            'items.*.expenses.*.amount'            => ['required', 'numeric', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'supplier_id'  => 'supplier',
            'costing_type' => 'costing type',
            'grn_ids'      => 'GRN list',
            'grn_ids.*'    => 'GRN',
        ];
    }
}
