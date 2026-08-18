<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Inventory\Enums\SalesOrderSource;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductLocationStore;

class StoreSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'reference_no'     => ['nullable', 'string', 'max:100'],
            'customer_id'      => ['required', 'integer', 'exists:inv_customer_masters,id'],
            'sales_person_id'  => ['required', 'integer', 'exists:users,id'],
            'order_taken_by'   => ['nullable', 'integer', 'exists:users,id'],
            'customer_type'    => ['nullable', 'string', Rule::in(['Trade', 'Retail', 'Wholesale', 'Corporate'])],
            'order_date'       => ['required', 'date'],
            'expected_date'    => ['nullable', 'date', 'after_or_equal:order_date'],
            'transaction_date' => ['nullable', 'date'],
            'order_source'     => ['nullable', Rule::enum(SalesOrderSource::class)],
            'delivery_address' => ['nullable', 'string', 'max:2000'],
            'transport_charge' => ['nullable', 'numeric', 'min:0'],
            'remarks'          => ['nullable', 'string', 'max:2000'],
            'status'           => ['nullable', 'string', Rule::in(['draft', 'confirmed'])],

            'items'                 => ['required', 'array', 'min:1'],
            'items.*.product_id'    => ['required', 'integer', 'exists:inv_products,id'],
            'items.*.unit_id'       => ['nullable', 'integer', 'exists:inv_unit_types,id'],
            // The unit the price is quoted PER (may differ from unit_id): a fabric
            // bought by the metre sells its yards at the per-metre price. Null =
            // the price is per the quantity's unit (legacy behaviour).
            'items.*.price_unit_id' => ['nullable', 'integer', 'exists:inv_unit_types,id'],
            'items.*.attribute_id'  => ['nullable', 'integer', 'exists:inv_attributes,id'],
            'items.*.quantity'      => ['required_without:items.*.piece_codes', 'nullable', 'numeric', 'gt:0'],
            'items.*.unit_price'    => ['required', 'numeric', 'min:0'],
            'items.*.discount'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.remarks'       => ['nullable', 'string', 'max:255'],
            'items.*.piece_codes'   => ['nullable', 'array'],
            'items.*.piece_codes.*' => ['string', 'distinct', 'exists:inv_grn_item_pieces,piece_code'],
            // How much of each roll this line takes, keyed by piece code, in the line's
            // own UOM. Omitted = spread the line quantity across the rolls (oldest first).
            'items.*.piece_takes'   => ['nullable', 'array'],
            'items.*.piece_takes.*' => ['numeric', 'gt:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $items      = (array) $this->input('items', []);
            $productIds = array_values(array_unique(array_filter(array_column($items, 'product_id'))));

            if ($productIds === []) {
                return;
            }

            $blocked = Product::whereIn('id', $productIds)
                ->where('not_allow_direct_sale', true)
                ->pluck('name', 'id');

            // Products with rolls currently in stock are already guarded by the roll
            // picker — a manual-quantity line for them is rejected outright regardless
            // of stock (see SalesOrderService::rejectManualLinesForRollTrackedProducts).
            // "Roll-tracked" is not a static product flag in this system — every product
            // gets roll/weight capture at GRN — so the only real signal is whether the
            // product currently has any in-stock GrnItemPiece rows.
            $productsWithRolls = GrnItemPiece::whereIn('product_id', $productIds)
                ->where('status', GrnItemPiece::STATUS_IN_STOCK)
                ->distinct()
                ->pluck('product_id');

            // Everything else has no such guard, so it needs a real stock check here: a
            // manual quantity line for a zero-stock product must be rejected. allow_minus
            // products are exempt — that flag is the existing, deliberate way to let a
            // product be sold/delivered below zero stock (enforced today at Delivery
            // Order confirm), so it must still be placeable on an SO.
            $checkableProducts = Product::whereIn('id', $productIds)
                ->whereNotIn('id', $productsWithRolls)
                ->where('allow_minus', false)
                ->pluck('name', 'id');

            $stockByProduct = $checkableProducts->isEmpty()
                ? collect()
                : ProductLocationStore::whereIn('product_id', $checkableProducts->keys())
                    ->selectRaw('product_id, SUM(current_stock) as total_stock')
                    ->groupBy('product_id')
                    ->pluck('total_stock', 'product_id');

            foreach ($items as $index => $item) {
                $productId = (int) ($item['product_id'] ?? 0);

                if ($blocked->has($productId)) {
                    $v->errors()->add(
                        "items.{$index}.product_id",
                        "{$blocked->get($productId)} is not allowed for direct sale.",
                    );
                    continue;
                }

                if (!$checkableProducts->has($productId)) {
                    continue;
                }

                $pieceCodes = array_values(array_filter((array) ($item['piece_codes'] ?? [])));
                $qty        = (float) ($item['quantity'] ?? 0);

                if ($pieceCodes === [] && $qty > 0 && (float) ($stockByProduct[$productId] ?? 0) <= 0) {
                    $v->errors()->add(
                        "items.{$index}.product_id",
                        "{$checkableProducts->get($productId)} has no available stock.",
                    );
                }
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'customer_id'        => 'customer',
            'sales_person_id'    => 'sales person',
            'order_taken_by'     => 'order taken by',
            'order_date'         => 'date',
            'expected_date'      => 'expected date',
            'transaction_date'   => 'transaction date',
            'order_source'       => 'order source',
            'delivery_address'   => 'delivery address',
            'transport_charge'   => 'transport charge',
            'items.*.product_id' => 'product',
            'items.*.unit_id'    => 'unit of measure',
            'items.*.quantity'   => 'quantity',
            'items.*.unit_price' => 'unit price',
        ];
    }
}
