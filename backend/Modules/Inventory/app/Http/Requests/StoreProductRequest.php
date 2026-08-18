<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Required identifiers
            'display_name'           => ['required', 'string', 'max:100'],
            'product_type'           => ['required', 'string', 'max:50'],
            'supplier_ids'           => ['required', 'array', 'min:1'],
            'supplier_ids.*'         => ['integer', 'exists:inv_supplier_masters,id'],

            // Optional identifiers
            'reference_no'           => ['nullable', 'string', 'max:50'],
            'ean_13'                 => ['nullable', 'string', 'max:50'],

            // Core
            'name'                   => ['required', 'string', 'max:100'],
            'description'            => ['nullable', 'string'],

            // Classification
            'category_id'            => ['required', 'integer', 'exists:inv_categories,id'],
            'location_id'            => ['nullable', 'integer', 'exists:inv_locations,id'],

            // Stocking (base) UOM — every stock balance is denominated in this unit
            'base_unit_type_id'      => ['required', 'integer', 'exists:inv_unit_types,id'],

            // Reorder
            'reorder_level'          => ['nullable', 'numeric', 'min:0'],
            'reorder_qty'            => ['nullable', 'numeric', 'min:0'],
            'reorder_period'         => ['nullable', 'integer', 'min:1'],

            // Stock control
            'stock_releasing_method' => ['nullable', 'string', 'max:50'],
            'tracking_type'          => ['nullable', 'string', Rule::in(['Batch', 'Serial'])],

            // Flags
            'lock_purchase'             => ['nullable', 'boolean'],
            'allow_complimentary_items' => ['nullable', 'boolean'],
            'free_issue'                => ['nullable', 'boolean'],
            'allow_minus'               => ['nullable', 'boolean'],
            'not_allow_direct_sale'     => ['nullable', 'boolean'],
            'non_returnable'            => ['nullable', 'boolean'],
            'is_empty'                  => ['nullable', 'boolean'],
            'service_charge'            => ['nullable', 'boolean'],
            'loyalty'                   => ['nullable', 'boolean'],
            'is_batch'                  => ['nullable', 'boolean'],
            'is_serial'                 => ['nullable', 'boolean'],

            // Location & Store pairs
            'location_stores'                    => ['nullable', 'array'],
            'location_stores.*.location_id'      => ['nullable', 'integer', 'exists:inv_locations,id'],
            'location_stores.*.store_id'         => ['nullable', 'integer', 'exists:inv_stores,id'],

            // Product attributes
            'product_attributes'                          => ['nullable', 'array'],
            'product_attributes.*.attribute_type_id'      => ['required', 'integer', 'exists:inv_attribute_types,id'],
            'product_attributes.*.attribute_id'           => ['required', 'integer', 'exists:inv_attributes,id'],

            // Cost details per sales channel — at least one channel required
            'cost_details'                                  => ['required', 'array', 'min:1'],
            'cost_details.*.sales_channel_id'               => ['required', 'integer', 'exists:inv_sales_channels,id'],
            'cost_details.*.unit_type_id'                   => ['nullable', 'integer', 'exists:inv_unit_types,id'],
            'cost_details.*.num_of_units'                   => ['required', 'numeric', 'min:0'],
            'cost_details.*.cost_price'                     => ['required', 'numeric', 'min:0'],
            'cost_details.*.margin'                         => ['nullable', 'numeric'],
            'cost_details.*.margin_type'                    => ['nullable', 'string', Rule::in(['percentage', 'amount'])],
            'cost_details.*.selling_price'                  => ['required', 'numeric', 'min:0'],
            'cost_details.*.max_price'                      => ['nullable', 'numeric', 'min:0'],
            'cost_details.*.min_price'                      => ['nullable', 'numeric', 'min:0'],
            'cost_details.*.wholesale_price'                => ['nullable', 'numeric', 'min:0'],
            'cost_details.*.sale_privileges_discount'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cost_details.*.purchasing_privileges_discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cost_details.required' => 'At least one sales channel is required.',
            'cost_details.min'      => 'At least one sales channel is required.',
            'cost_details.*.num_of_units.required'  => 'Number of units is required for each channel.',
            'cost_details.*.cost_price.required'    => 'Cost price is required for each channel.',
            'cost_details.*.selling_price.required' => 'Selling price is required for each channel.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'reference_no'                          => 'reference number',
            'ean_13'                                => 'EAN / barcode',
            'display_name'                          => 'display name',
            'product_type'                          => 'product type',
            'supplier_ids'                          => 'supplier',
            'reorder_level'                         => 'reorder level',
            'reorder_qty'                           => 'reorder quantity',
            'reorder_period'                        => 'reorder period',
            'stock_releasing_method'                => 'stock releasing method',
            'tracking_type'                         => 'tracking type',
            'base_unit_type_id'                     => 'stocking UOM',
            'cost_details.*.sales_channel_id'       => 'sales channel',
            'cost_details.*.num_of_units'           => 'number of units',
            'cost_details.*.cost_price'             => 'cost price',
            'cost_details.*.margin'                 => 'margin',
            'cost_details.*.selling_price'          => 'selling price',
            'cost_details.*.sale_privileges_discount' => 'privileges discount',
        ];
    }
}
