<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Inventory\Enums\ProformaInvoiceStatus;
use Modules\Inventory\Enums\SalesOrderSource;
use Modules\Inventory\Http\Requests\Concerns\ResolvesLineVariants;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductAttribute;
use Modules\Inventory\Models\ProductLocationStore;
use Modules\Inventory\Models\ProformaInvoiceItem;
use Modules\Inventory\Services\ColourStockService;
use Modules\Inventory\Services\VariantStockService;

class StoreSalesOrderRequest extends FormRequest
{
    use ResolvesLineVariants;

    /**
     * A line recalled from a proforma takes the proforma line's variant when it
     * sends none; every variant line's colour is derived from its variant.
     */
    protected function prepareForValidation(): void
    {
        $this->inheritLineVariants('proforma_item_id', 'inv_proforma_invoice_items');
        $this->prepareLineVariants();
    }

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
            'items.*.variant_id'    => ['nullable', 'integer'],
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
            // Line recalled from a proforma ("Create SO from Proforma") — soft link
            'items.*.proforma_item_id' => ['nullable', 'integer', 'exists:inv_proforma_invoice_items,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->validateLineVariants($v));

        $validator->after(function (Validator $v): void {
            $items      = (array) $this->input('items', []);

            $this->validateProformaLinks($v, $items);

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

            // Quantity-tracked products (finished goods, …) are stocked per colour:
            // a product that offers colours needs one on every line, and the stock
            // check is that colour's own balance, not the product total.
            $rollProductIds     = Product::rollTrackedIds(array_map('intval', $productIds));
            $colourProductIds   = ProductAttribute::colourProductIds(array_map('intval', $productIds));
            $colourStock        = app(ColourStockService::class);
            $variantStock       = app(VariantStockService::class);

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

                $pieceCodes  = array_values(array_filter((array) ($item['piece_codes'] ?? [])));
                $qty         = (float) ($item['quantity'] ?? 0);
                $isQtyTracked = ! in_array($productId, $rollProductIds, true);
                $attributeId  = !empty($item['attribute_id']) ? (int) $item['attribute_id'] : null;
                $variantId    = !empty($item['variant_id']) ? (int) $item['variant_id'] : null;

                // A variant line's colour comes from the variant (validateLineVariants
                // already demands the variant), so the colour rule is for the rest.
                if ($isQtyTracked && $variantId === null && $attributeId === null && in_array($productId, $colourProductIds, true)) {
                    $v->errors()->add("items.{$index}.attribute_id", 'Colour is required for this item.');
                    continue;
                }

                if (!$checkableProducts->has($productId)) {
                    continue;
                }

                // Variant products are stocked per variant: Red / M is its own balance.
                if ($isQtyTracked && $variantId !== null) {
                    if ($qty > 0 && $variantStock->available($productId, $variantId) <= 0) {
                        $v->errors()->add(
                            "items.{$index}.variant_id",
                            "{$checkableProducts->get($productId)} has no available stock in this variant.",
                        );
                    }
                    continue;
                }

                if ($isQtyTracked && $attributeId !== null && $qty > 0
                    && $colourStock->available($productId, $attributeId) <= 0) {
                    $v->errors()->add(
                        "items.{$index}.attribute_id",
                        "{$checkableProducts->get($productId)} has no available stock in this colour.",
                    );
                    continue;
                }

                if ($pieceCodes === [] && $qty > 0 && (float) ($stockByProduct[$productId] ?? 0) <= 0) {
                    $v->errors()->add(
                        "items.{$index}.product_id",
                        "{$checkableProducts->get($productId)} has no available stock.",
                    );
                }
            }
        });
    }

    /**
     * A line recalled from a proforma must come from an issued proforma of the same
     * customer, for the same product and in the same UOM — the quantity tracking
     * (recalled / invoiced vs quoted) compares quantities in that UOM.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function validateProformaLinks(Validator $v, array $items): void
    {
        $ids = collect($items)->pluck('proforma_item_id')->filter()->map(fn ($id) => (int) $id)->unique();
        if ($ids->isEmpty()) {
            return;
        }

        $proformaItems = ProformaInvoiceItem::with('proforma')->whereIn('id', $ids)->get()->keyBy('id');
        $customerId    = (int) $this->input('customer_id');

        foreach ($items as $index => $item) {
            if (empty($item['proforma_item_id'])) {
                continue;
            }

            $pfItem = $proformaItems->get((int) $item['proforma_item_id']);
            $field  = "items.{$index}.proforma_item_id";

            if ($pfItem === null || $pfItem->proforma === null) {
                $v->errors()->add($field, 'The proforma line no longer exists.');
            } elseif (!in_array($pfItem->proforma->status->value, ProformaInvoiceStatus::linkable(), true)) {
                $v->errors()->add($field, "Proforma {$pfItem->proforma->proforma_no} is not issued.");
            } elseif ((int) $pfItem->proforma->customer_id !== $customerId) {
                $v->errors()->add($field, "Proforma {$pfItem->proforma->proforma_no} belongs to another customer.");
            } elseif ((int) $pfItem->product_id !== (int) ($item['product_id'] ?? 0)) {
                $v->errors()->add($field, 'The line product differs from the proforma line.');
            } elseif ($pfItem->variant_id !== null && (int) $pfItem->variant_id !== (int) ($item['variant_id'] ?? 0)) {
                $v->errors()->add("items.{$index}.variant_id", 'Keep the proforma\'s variant on a recalled line.');
            } elseif ($pfItem->unit_id !== null && (int) $pfItem->unit_id !== (int) ($item['unit_id'] ?? 0)) {
                $v->errors()->add("items.{$index}.unit_id", "Keep the proforma's unit of measure on a recalled line.");
            }
        }
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
