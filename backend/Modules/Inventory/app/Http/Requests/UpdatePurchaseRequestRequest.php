<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\AttributeType;
use Modules\Inventory\Models\ProductAttribute;

class UpdatePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'request_date'        => ['required', 'date'],
            'reference_no'        => ['nullable', 'string', 'max:50'],
            'purpose'             => ['nullable', 'string', 'max:200'],
            'source_location_id'  => ['nullable', 'integer', 'exists:inv_locations,id'],
            'source_store_id'     => ['nullable', 'integer', 'exists:inv_stores,id'],
            'target_location_id'  => ['nullable', 'integer', 'exists:inv_locations,id'],
            'target_store_id'     => ['nullable', 'integer', 'exists:inv_stores,id'],
            'customer_id'         => ['nullable', 'integer', 'exists:inv_customer_masters,id'],
            'required_date'       => ['nullable', 'date', 'after_or_equal:request_date'],
            'transport_mode'      => ['nullable', 'string', 'max:100'],
            'remarks'             => ['nullable', 'string'],
            'submit_for_approval' => ['nullable', 'boolean'],
            'status'              => ['nullable', 'string', Rule::in(['draft', 'approved'])],

            'items'                        => ['required', 'array', 'min:1'],
            'items.*.product_id'           => ['required', 'integer', 'exists:inv_products,id'],
            'items.*.unit_id'              => ['required', 'integer', 'exists:inv_unit_types,id'],
            'items.*.attribute_id'         => ['nullable', 'integer', 'exists:inv_attributes,id'],
            'items.*.quantity'             => ['required', 'numeric', 'min:0.0001'],
            'items.*.estimated_unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.remarks'              => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items        = (array) $this->input('items', []);
            $productIds   = collect($items)->pluck('product_id')->filter()->unique()->map('intval')->values()->all();
            $colorTypeIds = AttributeType::whereRaw('LOWER(attribute_type_name) IN (?, ?)', ['color', 'colour'])->pluck('id');
            $colorProducts = ProductAttribute::whereIn('product_id', $productIds)
                ->whereIn('attribute_type_id', $colorTypeIds)
                ->pluck('product_id')->unique()->map('intval')->all();

            foreach ($items as $index => $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                if (in_array($productId, $colorProducts, true) && empty($item['attribute_id'])) {
                    $validator->errors()->add("items.{$index}.attribute_id", 'Color is required for this item.');
                }
            }
        });
    }
}
