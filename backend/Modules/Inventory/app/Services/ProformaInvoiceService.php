<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Models\Company;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\ProformaInvoiceData;
use Modules\Inventory\Enums\ProformaInvoiceStatus;
use Modules\Inventory\Models\CustomerMaster;
use Modules\Inventory\Models\Invoice;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProformaInvoice;
use Modules\Inventory\Models\ProformaInvoiceItem;

/**
 * Proforma invoices — the quotation-stage document. Raised BEFORE any sales
 * order, for any product (in stock or not; lines may mix both), with typed
 * quantities and prices. A proforma never reserves stock and has no receivable
 * or ledger effect; each line only records what stock was available when it
 * was issued (informational).
 *
 * Fulfilment: "Create SO from Proforma" recalls the remaining lines into a
 * sales order (rolls are picked there, as for any SO). SO lines keep a soft
 * link to their proforma line, so one proforma can feed several SOs and the
 * DO invoices bill the quoted prices (see InvoiceService::createFromDo).
 *
 * Proformas raised under the earlier SO-first flow (source_type sales_order)
 * remain viewable and keep linking through the same per-line mechanism.
 */
class ProformaInvoiceService
{
    /** Default offer validity when the form leaves valid_until empty. */
    private const DEFAULT_VALID_DAYS = 14;

    private const MONEY_SCALE = 2;

    private const EPSILON = 0.0001;

    /** VAT % assumed when a product carries no confirmed costing (same as InvoiceService). */
    private const FALLBACK_VAT_PCT = 18.0;

    public function __construct(
        private readonly ProformaInvoicingTracker $tracker,
        private readonly ProformaStockService $stock,
        private readonly ProductPricingService $pricing,
        private readonly UnitConversionService $units,
    ) {
    }

    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = ProformaInvoice::with(['customer', 'salesOrder'])
            ->orderByDesc('proforma_date')
            ->orderByDesc('id');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term): void {
                $q->where('proforma_no', 'like', $term)
                  ->orWhereHas('customer', fn ($c) => $c->where('customer_name', 'like', $term));
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('proforma_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('proforma_date', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    /** One proforma with its lines, recalled / invoiced quantities, linked SOs and invoices. */
    public function find(int $id): ProformaInvoice
    {
        $proforma = ProformaInvoice::with([
            'items.product',
            'items.unit',
            'items.priceUnit',
            'items.attribute',
            'items.variant',
            'salesOrder',
            'customer',
            'company',
        ])->findOrFail($id);

        $invoiced = $this->tracker->invoicedQuantities($proforma->id);
        $recalled = $this->tracker->recalledQuantities($proforma->id);

        foreach ($proforma->items as $item) {
            $item->setAttribute('invoiced_quantity', (float) ($invoiced[$item->id] ?? 0));
            $item->setAttribute('recalled_quantity', (float) ($recalled[$item->id] ?? 0));
        }

        // Invoices are found through their lines — one proforma can be billed by many DO invoices
        $proforma->setRelation('invoices', Invoice::with('deliveryOrder:id,do_no')
            ->whereHas('items', fn ($q) => $q->whereIn('proforma_item_id', $proforma->items->pluck('id')))
            ->orderBy('id')
            ->get());

        $proforma->setAttribute('linked_sales_orders', $this->tracker->linkedSalesOrders($proforma->id));

        return $proforma;
    }

    /** Lock-free preview; the real number is generated inside the create transaction. */
    public function nextProformaNo(?int $customerId, ?string $proformaDate): ?string
    {
        $customer = $customerId !== null ? CustomerMaster::find($customerId) : null;
        if ($customer === null) {
            return null;
        }

        return $this->buildProformaNo(lock: false, customerCode: $customer->customer_code ?? 'CUST', proformaDate: $proformaDate);
    }

    /**
     * Live stock situation of form lines (the badge next to each line).
     *
     * @param  array<int, array{product_id:int, attribute_id:?int, unit_id:?int, quantity:?float}> $lines
     * @return array<int, array{available_qty: float, stock_status: string}>
     */
    public function stockCheck(array $lines): array
    {
        return array_map(fn (array $l) => $this->stock->check(
            (int) $l['product_id'],
            !empty($l['attribute_id']) ? (int) $l['attribute_id'] : null,
            !empty($l['unit_id']) ? (int) $l['unit_id'] : null,
            (float) ($l['quantity'] ?? 0),
            !empty($l['variant_id']) ? (int) $l['variant_id'] : null,
        ), array_values($lines));
    }

    /**
     * Both price sets of a quote line, per the line's unit — the same pair a DO
     * invoice switches between with its Tax / Non-Tax toggle:
     *
     *   unit_price               Non-Tax: the after-tax price, VAT inside (what an SO line holds)
     *   tax_unit_price + tax_vat_pct  Tax: before-tax price, VAT added per line
     *
     * Source: the product's newest confirmed costing (selling + before-tax price and
     * its VAT %); without one, the price-list selling price with the default VAT
     * stripped out — so before-tax + VAT always lands on the Non-Tax price.
     *
     * @param  array<int, array{product_id:int, attribute_id:?int, unit_id:?int}> $lines
     * @return array<int, array{unit_price:?float, tax_unit_price:?float, tax_vat_pct:float, source:?string}>
     */
    public function pricePreview(array $lines): array
    {
        return array_map(function (array $l): array {
            $productId   = (int) $l['product_id'];
            $attributeId = !empty($l['attribute_id']) ? (int) $l['attribute_id'] : null;
            $variantId   = !empty($l['variant_id']) ? (int) $l['variant_id'] : null;
            $factor      = $this->lineUnitFactor($productId, !empty($l['unit_id']) ? (int) $l['unit_id'] : null);

            $costing = $this->pricing->latestCostingPricingFor($productId, $attributeId, $variantId);
            if ($costing !== null) {
                return [
                    'unit_price'     => $this->money($costing['selling_price'] * $factor),
                    'tax_unit_price' => $this->money($costing['before_tax_price'] * $factor),
                    'tax_vat_pct'    => (float) $costing['vat_pct'],
                    'source'         => 'costing',
                ];
            }

            $list = $this->pricing->sellingPriceFor($productId, $variantId);
            if ($list === null) {
                return ['unit_price' => null, 'tax_unit_price' => null, 'tax_vat_pct' => self::FALLBACK_VAT_PCT, 'source' => null];
            }

            $nonTax = $list * $factor;

            return [
                'unit_price'     => $this->money($nonTax),
                'tax_unit_price' => $this->money($nonTax / (1 + self::FALLBACK_VAT_PCT / 100)),
                'tax_vat_pct'    => self::FALLBACK_VAT_PCT,
                'source'         => 'price_list',
            ];
        }, array_values($lines));
    }

    /** Stocking units per ONE line unit (prices are filed per the stocking UOM). */
    private function lineUnitFactor(int $productId, ?int $unitId): float
    {
        $baseUnitId = Product::whereKey($productId)->value('base_unit_type_id');
        if ($unitId === null || $baseUnitId === null || $unitId === (int) $baseUnitId) {
            return 1.0;
        }

        $rate = $this->units->tryFactor($unitId, (int) $baseUnitId);

        return ($rate !== null && $rate > 0.0) ? $rate : 1.0;
    }

    public function create(ProformaInvoiceData $data): ProformaInvoice
    {
        return DB::transaction(function () use ($data): ProformaInvoice {
            $customer = CustomerMaster::findOrFail($data->customerId);

            $proforma = ProformaInvoice::create([
                'proforma_no'      => $this->buildProformaNo(
                    lock: true,
                    customerCode: $customer->customer_code ?? 'CUST',
                    proformaDate: $data->proformaDate,
                ),
                'source_type'      => ProformaInvoice::SOURCE_DIRECT,
                'source_id'        => null,
                'so_id'            => null,
                'customer_id'      => $customer->id,
                'company_id'       => $data->companyId ?? $this->defaultCompanyId(),
                'proforma_date'    => $data->proformaDate,
                'valid_until'      => $data->validUntil
                    ?? Carbon::parse($data->proformaDate)->addDays(self::DEFAULT_VALID_DAYS)->toDateString(),
                'payment_terms'    => $data->paymentTerms,
                'invoice_type'     => $data->invoiceType ?? $this->defaultInvoiceType($customer),
                'status'           => ProformaInvoiceStatus::Draft,
                'transport_charge' => $this->money($data->transportCharge ?? 0),
                'delivery_address' => $data->deliveryAddress ?? $this->shippingAddress($customer),
                'remarks'          => $data->remarks,
                'created_by'       => Auth::id(),
                'subtotal'         => 0,
                'grand_total'      => 0,
            ]);

            $this->syncLines($proforma, $data->items);

            return $this->find($proforma->id);
        });
    }

    public function update(ProformaInvoice $proforma, ProformaInvoiceData $data): ProformaInvoice
    {
        return DB::transaction(function () use ($proforma, $data): ProformaInvoice {
            $proforma = ProformaInvoice::whereKey($proforma->id)->lockForUpdate()->firstOrFail();

            if (!$proforma->status->isEditable()) {
                abort(422, 'Only draft proforma invoices can be edited.');
            }

            if ($proforma->source_type !== ProformaInvoice::SOURCE_DIRECT) {
                abort(422, 'This proforma was raised from a sales order and cannot be edited — cancel it and raise a new one.');
            }

            $proforma->update([
                'proforma_date'    => $data->proformaDate,
                'valid_until'      => $data->validUntil,
                'payment_terms'    => $data->paymentTerms,
                'invoice_type'     => $data->invoiceType ?? $proforma->invoice_type,
                'company_id'       => $data->companyId ?? $proforma->company_id,
                'transport_charge' => $this->money($data->transportCharge ?? (float) $proforma->transport_charge),
                'delivery_address' => $data->deliveryAddress,
                'remarks'          => $data->remarks,
            ]);

            $this->syncLines($proforma, $data->items);

            return $this->find($proforma->id);
        });
    }

    /** User transitions only: draft → issued | cancelled, issued → cancelled. */
    public function updateStatus(ProformaInvoice $proforma, string $status): ProformaInvoice
    {
        $newStatus = ProformaInvoiceStatus::tryFrom($status)
            ?? abort(422, "Unknown proforma status '{$status}'.");

        $allowedTransitions = [
            ProformaInvoiceStatus::Draft->value  => [ProformaInvoiceStatus::Issued, ProformaInvoiceStatus::Cancelled],
            ProformaInvoiceStatus::Issued->value => [ProformaInvoiceStatus::Cancelled],
        ];

        $allowed = $allowedTransitions[$proforma->status->value] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            abort(422, "Cannot change proforma from {$proforma->status->label()} to {$newStatus->label()}.");
        }

        if ($newStatus === ProformaInvoiceStatus::Cancelled) {
            if ($this->tracker->recalledQuantities($proforma->id)->sum() > self::EPSILON) {
                abort(422, 'This proforma has been recalled into sales orders and cannot be cancelled.');
            }

            if ($this->tracker->invoicedQuantities($proforma->id)->sum() > self::EPSILON) {
                abort(422, 'This proforma is linked to invoices and cannot be cancelled.');
            }
        }

        DB::transaction(function () use ($proforma, $newStatus): void {
            // The quote states what could ship on the day it went out
            if ($newStatus === ProformaInvoiceStatus::Issued) {
                foreach ($proforma->items()->get() as $item) {
                    $item->update($this->stock->check(
                        (int) $item->product_id,
                        $item->attribute_id,
                        $item->unit_id,
                        (float) $item->quantity,
                        $item->variant_id,
                    ));
                }
            }

            $proforma->update([
                'status'       => $newStatus,
                'issued_at'    => $newStatus === ProformaInvoiceStatus::Issued ? now() : $proforma->issued_at,
                'cancelled_at' => $newStatus === ProformaInvoiceStatus::Cancelled ? now() : $proforma->cancelled_at,
            ]);
        });

        return $this->find($proforma->id);
    }

    public function delete(ProformaInvoice $proforma): void
    {
        if ($proforma->status !== ProformaInvoiceStatus::Draft) {
            abort(422, 'Only draft proforma invoices can be deleted.');
        }

        DB::transaction(function () use ($proforma): void {
            $proforma->items()->delete();
            $proforma->delete();
        });
    }

    /**
     * What "Create SO from Proforma" pre-fills: the customer, and every line with
     * quantity still to recall, priced as an SO line (SO prices carry VAT inside,
     * so a tax proforma's before-tax price + VAT becomes the SO price) and with
     * today's stock so the form can hold back lines that cannot ship yet.
     *
     * @return array<string, mixed>
     */
    public function recallSource(int $id): array
    {
        $proforma = $this->find($id);

        if (!in_array($proforma->status, [ProformaInvoiceStatus::Issued, ProformaInvoiceStatus::PartiallyInvoiced], true)) {
            abort(422, "Only an issued proforma can be recalled into a sales order ({$proforma->proforma_no} is {$proforma->status->label()}).");
        }

        $alreadyRecalled = $proforma->items->sum(fn ($i) => (float) $i->getAttribute('recalled_quantity')) > self::EPSILON;

        $lines = $proforma->items
            ->map(function (ProformaInvoiceItem $item) use ($proforma): array {
                $remaining = max(0.0, (float) $item->quantity - (float) $item->getAttribute('recalled_quantity'));
                $stock     = $this->stock->check((int) $item->product_id, $item->attribute_id, $item->unit_id, $remaining, $item->variant_id);
                $price     = (float) $item->unit_price;
                $soPrice   = $proforma->invoice_type === 'tax' ? $price * (1 + (float) $item->tax / 100) : $price;

                return [
                    'proforma_item_id' => $item->id,
                    'product'          => $item->product ? [
                        'id'           => $item->product->id,
                        'name'         => $item->product->name,
                        'product_code' => $item->product->product_code,
                    ] : null,
                    'product_id'       => $item->product_id,
                    'unit_id'          => $item->unit_id,
                    'attribute_id'     => $item->attribute_id,
                    'variant_id'       => $item->variant_id,
                    'variant'          => $item->variant ? ['id' => $item->variant->id, 'sku' => $item->variant->sku, 'name' => $item->variant->variant_label] : null,
                    'quantity'         => (float) $item->quantity,
                    'recalled_quantity' => (float) $item->getAttribute('recalled_quantity'),
                    'remaining_quantity' => round($remaining, 4),
                    'so_unit_price'    => $this->money($soPrice),
                    'discount'         => (float) $item->discount,
                    'available_qty'    => $stock['available_qty'],
                    'stock_status'     => $stock['stock_status'],
                ];
            })
            ->filter(fn (array $l) => $l['remaining_quantity'] > self::EPSILON)
            ->values();

        return [
            'proforma' => [
                'id'               => $proforma->id,
                'proforma_no'      => $proforma->proforma_no,
                'invoice_type'     => $proforma->invoice_type,
                'customer_id'      => $proforma->customer_id,
                'customer_type'    => $proforma->customer?->customer_type,
                'delivery_address' => $proforma->delivery_address,
                // Transport is charged once — only the first SO recalls it
                'transport_charge' => $alreadyRecalled ? 0.0 : (float) $proforma->transport_charge,
                'remarks'          => $proforma->remarks,
            ],
            'lines' => $lines->all(),
        ];
    }

    /**
     * Replaces every line with the form's lines — typed quantity and price. A
     * Non-Tax proforma never adds VAT (Tax % forced to 0).
     *
     * @param array<int, array<string, mixed>> $lines
     */
    private function syncLines(ProformaInvoice $proforma, array $lines): void
    {
        $proforma->items()->delete();

        $subtotal   = 0.0;
        $lineTotals = 0.0;

        foreach ($lines as $line) {
            $qty      = (float) $line['quantity'];
            $price    = $this->money((float) $line['unit_price']);
            $discount = (float) ($line['discount'] ?? 0);
            $tax      = $proforma->invoice_type === 'non_tax' ? 0.0 : (float) ($line['tax'] ?? 0);
            $gross    = $qty * $price;

            $lineTotal = $this->money($gross - ($gross * $discount / 100) + ($gross * $tax / 100));
            $unitId    = !empty($line['unit_id']) ? (int) $line['unit_id'] : null;

            ProformaInvoiceItem::create([
                'proforma_id'      => $proforma->id,
                'source_item_type' => ProformaInvoiceItem::SOURCE_DIRECT,
                'source_item_id'   => null,
                'so_item_id'       => null,
                'product_id'       => (int) $line['product_id'],
                'unit_id'          => $unitId,
                // The price is quoted per the line's own unit
                'price_unit_id'    => $unitId,
                'attribute_id'     => !empty($line['attribute_id']) ? (int) $line['attribute_id'] : null,
                'variant_id'       => !empty($line['variant_id']) ? (int) $line['variant_id'] : null,
                'quantity'         => $qty,
                'unit_price'       => $price,
                'discount'         => $discount,
                'tax'              => $tax,
                'line_total'       => $lineTotal,
                'remarks'          => $line['remarks'] ?? null,
            ]);

            $subtotal   += $gross;
            $lineTotals += $lineTotal;
        }

        $proforma->update([
            'subtotal'    => $this->money($subtotal),
            'grand_total' => $this->money($lineTotals + (float) $proforma->transport_charge),
        ]);
    }

    /**
     * PI-YYMMM_QQQQ_XXXX — mirrors the invoice number: proforma month, the customer's
     * code (spaces and dashes stripped) and one global running serial that never resets.
     */
    private function buildProformaNo(bool $lock, string $customerCode, ?string $proformaDate): string
    {
        $date   = $proformaDate ? Carbon::parse($proformaDate) : now();
        $code   = str_replace([' ', '-'], '', $customerCode) ?: 'CUST';
        $serial = str_pad((string) $this->nextSerial($lock), 4, '0', STR_PAD_LEFT);

        return 'PI-' . $date->format('y') . strtoupper($date->format('M')) . '_' . $code . '_' . $serial;
    }

    private function nextSerial(bool $lock): int
    {
        $query = ProformaInvoice::withTrashed()->orderByDesc('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $last = $query->value('proforma_no');

        return ($last && preg_match('/(\d+)$/', $last, $m)) ? ((int) $m[1] + 1) : 1;
    }

    /** Same composition as the customer picker (CustomerController::all). */
    private function shippingAddress(CustomerMaster $c): ?string
    {
        $address = implode(', ', array_filter([
            $c->shipping_address_line1,
            $c->shipping_address_line2,
            $c->shipping_address_line3,
            $c->shipping_city,
            $c->shipping_state_province,
            $c->shipping_zip_postal,
            $c->shipping_country,
        ]));

        return $address !== '' ? $address : null;
    }

    /** Same default as the invoice: only a VAT-registered customer (TIN …7000) is quoted Tax. */
    private function defaultInvoiceType(CustomerMaster $customer): string
    {
        return $customer->isVatRegistered() ? 'tax' : 'non_tax';
    }

    private function defaultCompanyId(): ?int
    {
        $id = Company::query()->orderBy('id')->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function money(float $value): float
    {
        return round($value, self::MONEY_SCALE);
    }
}
