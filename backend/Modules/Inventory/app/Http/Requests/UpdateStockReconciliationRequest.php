<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStockReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'product_id'    => ['required', 'integer', 'exists:inv_products,id'],
            'attribute_id'  => ['nullable', 'integer', 'exists:inv_attributes,id'],
            'store_id'      => ['required', 'integer', 'exists:inv_stores,id'],
            'location_id'   => ['required', 'integer', 'exists:inv_locations,id'],
            'batch_id'      => ['nullable', 'integer', 'exists:inv_batches,id'],
            'unit_id'       => ['nullable', 'integer', 'exists:inv_unit_types,id'],
            'counted_qty'   => ['nullable', 'numeric', 'min:0'],

            'source_type'    => ['nullable', 'string', Rule::in(['grn'])],
            'source_id'      => ['nullable', 'integer'],
            'also_adjust_po' => ['nullable', 'boolean'],

            'reason'   => ['required', 'string', 'max:255'],
            'remarks'  => ['nullable', 'string'],

            'pieces'                      => ['nullable', 'array'],
            'pieces.*.grn_item_piece_id'  => ['required_with:pieces', 'integer', 'exists:inv_grn_item_pieces,id'],
            'pieces.*.new_weight'         => ['required_with:pieces', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasCountedQty = $this->filled('counted_qty');
            $hasPieces     = !empty($this->input('pieces', []));

            if (!$hasCountedQty && !$hasPieces) {
                $validator->errors()->add('counted_qty', 'Enter the counted quantity, or attach at least one roll to correct.');
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'product_id'  => 'product',
            'store_id'    => 'store',
            'location_id' => 'location',
            'counted_qty' => 'counted quantity',
        ];
    }
}
