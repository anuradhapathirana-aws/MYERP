<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\AttributeType;
use Modules\Inventory\Models\ProductAttribute;
use Modules\Inventory\Models\UnitType;

class StoreGoodsReceivedNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'po_id'            => ['nullable', 'integer', 'exists:inv_purchase_orders,id'],
            'supplier_id'      => ['required', 'integer', 'exists:inv_supplier_masters,id'],
            'grn_date'         => ['required', 'date'],
            'transaction_date' => ['nullable', 'date'],
            'reference_no'     => ['nullable', 'string', 'max:100'],
            'shipping_code'    => [
                'required', 'string', 'max:100',
                Rule::unique('inv_goods_received_notes', 'shipping_code')->whereNull('deleted_at'),
            ],
            'store_id'         => ['required', 'integer', 'exists:inv_stores,id'],
            'location_id'      => ['required', 'integer', 'exists:inv_locations,id'],
            'remarks'          => ['nullable', 'string'],
            'payment_terms'    => ['nullable', 'string', 'max:100'],
            'attachments'      => ['nullable', 'array'],
            'attachments.*'    => ['nullable', 'string'],

            'items'                       => ['required', 'array', 'min:1'],
            'items.*.po_item_id'          => ['nullable', 'integer', 'exists:inv_purchase_order_items,id'],
            'items.*.product_id'          => ['required', 'integer', 'exists:inv_products,id'],
            'items.*.attribute_id'        => ['nullable', 'integer', 'exists:inv_attributes,id'],
            'items.*.unit_id'             => ['required', 'integer', 'exists:inv_unit_types,id'],
            'items.*.quantity_received'   => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price'          => ['required', 'numeric', 'min:0'],
            'items.*.discount'            => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax'                 => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.batch_no'            => ['nullable', 'string', 'max:100'],
            'items.*.expiry_date'         => ['nullable', 'date'],

            'items.*.batches'                         => ['nullable', 'array'],
            'items.*.batches.*.batch_no'              => ['required_with:items.*.batches', 'string', 'max:100'],
            'items.*.batches.*.quantity'              => ['required_with:items.*.batches', 'numeric', 'min:0.0001'],
            'items.*.batches.*.mfg_date'              => ['nullable', 'date'],
            'items.*.batches.*.expiry_date'           => ['nullable', 'date'],
            'items.*.batches.*.supplier_batch_no'     => ['nullable', 'string', 'max:100'],
            'items.*.batches.*.status'                => ['nullable', 'string', 'in:active,quarantine,on_hold'],
            'items.*.batches.*.country_of_origin'     => ['nullable', 'string', 'max:100'],
            'items.*.batches.*.notes'                 => ['nullable', 'string', 'max:500'],

            // Every GRN line must be received as physical rolls — they become
            // the QR-labelled pieces that sales orders allocate.
            'items.*.rolls'              => ['required', 'array', 'min:1'],
            'items.*.rolls.*.roll_no'    => ['required', 'string', 'max:100'],
            'items.*.rolls.*.weight'     => ['required', 'numeric', 'min:0.0001'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'shipping_code.unique' => 'This shipping code is already used by another GRN.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'po_id'                     => 'purchase order',
            'supplier_id'               => 'supplier',
            'grn_date'                  => 'GRN date',
            'transaction_date'          => 'transaction date',
            'shipping_code'             => 'shipping code',
            'items.*.po_item_id'        => 'PO item',
            'items.*.product_id'        => 'product',
            'items.*.unit_id'           => 'unit of measure',
            'items.*.quantity_received' => 'quantity received',
            'items.*.unit_price'        => 'unit price',
            'items.*.rolls.*.roll_no'   => 'roll no',
            'items.*.rolls.*.weight'    => 'roll weight',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items = (array) $this->input('items', []);

            // ── Roll weight validation ────────────────────────────────────
            $unitIds = collect($items)->pluck('unit_id')->filter()->unique()->values();
            $weightUnitIds = UnitType::with('category:id,name')
                ->whereIn('id', $unitIds)
                ->get()
                ->filter(fn (UnitType $unit) => $unit->category?->name === 'Weight')
                ->pluck('id')
                ->all();

            foreach ($items as $index => $item) {
                $rolls = $item['rolls'] ?? [];
                if (empty($rolls) || !in_array((int) ($item['unit_id'] ?? 0), $weightUnitIds, true)) {
                    continue;
                }

                $required = (float) ($item['quantity_received'] ?? 0);
                $entered  = array_sum(array_map(fn ($roll) => (float) ($roll['weight'] ?? 0), $rolls));

                if (abs($required - $entered) > 0.0001) {
                    $validator->errors()->add(
                        "items.{$index}.rolls",
                        "Sum of roll weights ({$entered}) must equal quantity received ({$required})."
                    );
                }
            }

            // ── Color attribute validation ─────────────────────────────────
            // Products that have a "Color/Colour" attribute type must supply attribute_id.
            $productIds   = collect($items)->pluck('product_id')->filter()->unique()->map('intval')->values()->all();
            $colorTypeIds = AttributeType::whereRaw('LOWER(attribute_type_name) IN (?, ?)', ['color', 'colour'])
                ->pluck('id');
            $colorProducts = ProductAttribute::whereIn('product_id', $productIds)
                ->whereIn('attribute_type_id', $colorTypeIds)
                ->pluck('product_id')
                ->unique()
                ->map('intval')
                ->all();

            foreach ($items as $index => $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                if (in_array($productId, $colorProducts, true) && empty($item['attribute_id'])) {
                    $validator->errors()->add("items.{$index}.attribute_id", 'Color is required for this item.');
                }
            }
        });
    }
}
