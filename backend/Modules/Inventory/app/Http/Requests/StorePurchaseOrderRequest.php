<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Enums\PurchaseOrderStatus;
use Modules\Inventory\Models\AttributeType;
use Modules\Inventory\Models\ProductAttribute;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'po_no'                  => ['required', 'string', 'max:30', 'unique:inv_purchase_orders,po_no'],
            'pr_id'                  => ['nullable', 'integer', 'exists:inv_purchase_requests,id'],
            'supplier_id'            => ['required', 'integer', 'exists:inv_supplier_masters,id'],
            'store_id'               => ['required', 'integer', 'exists:inv_stores,id'],
            'location_id'            => ['required', 'integer', 'exists:inv_locations,id'],
            'order_date'             => ['required', 'date'],
            'expected_delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'reference_no'           => ['nullable', 'string', 'max:100'],
            'payment_terms'          => ['nullable', 'string', 'max:100'],
            'contact_person_name'    => ['required', 'string', 'max:100'],
            'contact_person_phone'   => ['required', 'string', 'max:30'],
            'is_consignment'         => ['nullable', 'boolean'],
            'billing_address'        => ['required', 'string', 'max:500'],
            'shipping_address'       => ['nullable', 'string', 'max:500'],
            'remarks'                => ['nullable', 'string', 'max:1000'],
            'status'                 => ['nullable', 'string', Rule::in(array_column(PurchaseOrderStatus::cases(), 'value'))],

            'items'                      => ['required', 'array', 'min:1'],
            'items.*.product_id'         => ['required', 'integer', 'exists:inv_products,id'],
            'items.*.unit_id'            => ['required', 'integer', 'exists:inv_unit_types,id'],
            'items.*.attribute_id'       => ['nullable', 'integer', 'exists:inv_attributes,id'],
            'items.*.pr_item_id'         => ['nullable', 'integer', 'exists:inv_purchase_request_items,id'],
            'items.*.quantity_ordered'   => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price'         => ['required', 'numeric', 'min:0'],
            'items.*.discount'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax'                => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.remarks'            => ['nullable', 'string', 'max:255'],
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

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'po_no'                    => 'PO number',
            'supplier_id'              => 'supplier',
            'store_id'                 => 'store',
            'location_id'              => 'location',
            'order_date'               => 'transaction date',
            'expected_delivery_date'   => 'expected delivery date',
            'contact_person_name'      => 'contact person',
            'contact_person_phone'     => 'contact phone',
            'billing_address'          => 'billing address',
            'items.*.product_id'       => 'product',
            'items.*.unit_id'          => 'unit of measure',
            'items.*.quantity_ordered' => 'quantity',
            'items.*.unit_price'       => 'unit price',
        ];
    }
}
