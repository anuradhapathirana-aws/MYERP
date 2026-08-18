<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\InvoiceData;
use Modules\Inventory\Enums\DeliveryOrderStatus;
use Modules\Inventory\Enums\InvoiceStatus;
use Modules\Inventory\Enums\SalesOrderStatus;
use Modules\Inventory\Models\Company;
use Modules\Inventory\Models\DeliveryOrder;
use Modules\Inventory\Models\Invoice;
use Modules\Inventory\Models\InvoiceItem;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\SalesOrder;
use Modules\Inventory\Models\SalesOrderItem;

/**
 * Invoices bill exactly one confirmed delivery order (do_id set, 1:1) — the
 * stock behind the invoice always left at DO confirm. Direct-SO (advance)
 * invoices (do_id null) can no longer be created; existing ones remain
 * viewable/editable and still block per-DO billing on their SO.
 *
 * Every invoice is either a TAX or a NON-TAX invoice:
 *   tax     — lines bill the costing's BEFORE-tax price and the VAT the costing
 *             used is added per line (Tax %); uncosted lines back-compute
 *             SO price ÷ (1 + 18%) so the customer total stays the SO's.
 *   non_tax — lines bill the SO price as-is (the costing's AFTER-tax price,
 *             VAT already inside) and no VAT is added (Tax % forced to 0).
 */
class InvoiceService
{
    /** VAT % assumed when a line's rolls carry no confirmed costing. */
    private const FALLBACK_VAT_PCT = 18.0;

    /** Decimal places every invoice money amount is rounded and stored at. */
    private const MONEY_SCALE = 2;

    public function __construct(
        private readonly ProductPricingService $pricing,
        private readonly UnitConversionService $units,
    ) {
    }
    /** @param array<string, mixed> $filters */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $query = Invoice::with(['customer', 'salesOrder', 'deliveryOrder'])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id');

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term): void {
                $q->where('invoice_no', 'like', $term)
                  ->orWhereHas('salesOrder', fn ($s) => $s->where('so_no', 'like', $term))
                  ->orWhereHas('customer', fn ($c) => $c->where('customer_name', 'like', $term));
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if (!empty($filters['so_id'])) {
            $query->where('so_id', (int) $filters['so_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('invoice_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('invoice_date', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    public function find(int $id): Invoice
    {
        return Invoice::with([
            'items.product',
            'items.unit',
            'items.priceUnit',
            'items.attribute',
            'salesOrder',
            'deliveryOrder',
            'customer',
            'company',
        ])->findOrFail($id);
    }

    /**
     * Lock-free preview; the real number is generated inside the create transaction.
     * The serial needs a customer, so there's nothing to preview until a delivery
     * order (and therefore a customer) has been picked.
     */
    public function nextInvoiceNo(?int $doId, ?string $invoiceDate): ?string
    {
        if ($doId === null) {
            return null;
        }

        $do = DeliveryOrder::with('salesOrder.customer')->find($doId);
        if (!$do || !$do->salesOrder) {
            return null;
        }

        return $this->buildInvoiceNo(
            lock: false,
            customerCode: $do->salesOrder->customer?->customer_code ?? 'CUST',
            invoiceDate: $invoiceDate,
        );
    }

    /**
     * Billing preview for a direct-SO (advance) invoice.
     *
     * @return array<string, mixed>
     */
    public function billingSourceForSo(int $soId): array
    {
        $so = SalesOrder::with(['items.product', 'items.unit', 'items.attribute', 'customer'])
            ->findOrFail($soId);

        return [
            'source'      => 'so',
            'sales_order' => $this->soSummary($so),
            'guards'      => $this->billingGuards($so, forDirect: true),
            'default_transport_charge' => $this->defaultTransportCharge($so),
            'items'       => $so->items->map(fn (SalesOrderItem $item) => $this->itemPreview($item, (float) $item->quantity, null))->values()->all(),
        ];
    }

    /**
     * Billing preview for a per-DO invoice.
     *
     * @return array<string, mixed>
     */
    public function billingSourceForDo(int $doId): array
    {
        $do = DeliveryOrder::with(['items.soItem', 'salesOrder.customer', 'salesOrder.items'])
            ->findOrFail($doId);

        $so = $do->salesOrder;

        return [
            'source'         => 'do',
            'delivery_order' => [
                'id'     => $do->id,
                'do_no'  => $do->do_no,
                'status' => $do->status->value,
            ],
            'sales_order' => $this->soSummary($so),
            'guards'      => $this->billingGuards($so, forDirect: false, do: $do),
            'default_transport_charge' => $this->defaultTransportCharge($so),
            'items'       => $do->items
                ->map(fn ($doItem) => $this->itemPreview($doItem->soItem, (float) $doItem->quantity, $doItem->id))
                ->values()
                ->all(),
        ];
    }

    public function create(InvoiceData $data): Invoice
    {
        if ($data->doId === null) {
            abort(422, 'An invoice must bill a confirmed delivery order.');
        }

        return $this->createFromDo($data->doId, $data);
    }

    public function update(Invoice $invoice, InvoiceData $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data): Invoice {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (!$invoice->status->isEditable()) {
                abort(422, 'Only draft invoices can be edited.');
            }

            $invoice->update([
                'invoice_date'     => $data->invoiceDate,
                'due_date'         => $data->dueDate,
                'invoice_type'     => $data->invoiceType ?? $invoice->invoice_type,
                'transport_charge' => $data->transportCharge !== null
                    ? $this->money($data->transportCharge)
                    : $invoice->transport_charge,
                'company_id'       => $data->companyId ?? $invoice->company_id,
                'delivery_address' => $data->deliveryAddress,
                'remarks'          => $data->remarks,
                'mode_of_payment'  => $data->modeOfPayment,
            ]);

            // Draft re-pricing: quantities are fixed by the source document;
            // only price/discount/tax/remarks may change per line.
            $this->applyItemOverrides($invoice, $data->items);
            $this->enforceInvoiceType($invoice);
            $this->recalculateTotals($invoice);

            return $this->find($invoice->id);
        });
    }

    public function updateStatus(Invoice $invoice, string $status): Invoice
    {
        $newStatus = InvoiceStatus::from($status);

        $allowedTransitions = [
            InvoiceStatus::Draft->value  => [InvoiceStatus::Issued, InvoiceStatus::Cancelled],
            InvoiceStatus::Issued->value => [InvoiceStatus::Paid, InvoiceStatus::Cancelled],
        ];

        $allowed = $allowedTransitions[$invoice->status->value] ?? [];
        if (!in_array($newStatus, $allowed)) {
            abort(422, "Cannot transition invoice from {$invoice->status->label()} to {$newStatus->label()}.");
        }

        $invoice->update([
            'status'    => $newStatus,
            'issued_at' => $newStatus === InvoiceStatus::Issued ? now() : $invoice->issued_at,
            'paid_at'   => $newStatus === InvoiceStatus::Paid ? now() : $invoice->paid_at,
        ]);

        return $invoice;
    }

    public function delete(Invoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            abort(422, 'Only draft invoices can be deleted.');
        }

        DB::transaction(function () use ($invoice): void {
            $invoice->items()->delete();
            $invoice->delete();
        });
    }

    private function createFromDo(int $doId, InvoiceData $data): Invoice
    {
        return DB::transaction(function () use ($doId, $data): Invoice {
            $do = DeliveryOrder::with(['items.soItem'])
                ->whereKey($doId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($do->status !== DeliveryOrderStatus::Confirmed) {
                abort(422, 'Only confirmed delivery orders can be invoiced.');
            }

            if ($this->doHasLiveInvoice($do->id)) {
                abort(422, 'This delivery order is already invoiced.');
            }

            $so = SalesOrder::findOrFail($do->so_id);

            if ($this->soHasLiveDirectInvoice($so->id)) {
                abort(422, "Sales order {$so->so_no} is billed directly — per-delivery invoices are not allowed.");
            }

            $invoice = Invoice::create([
                'invoice_no'       => $this->buildInvoiceNo(
                    lock: true,
                    customerCode: $so->customer?->customer_code ?? 'CUST',
                    invoiceDate: $data->invoiceDate,
                ),
                'so_id'            => $so->id,
                'do_id'            => $do->id,
                'customer_id'      => $so->customer_id,
                'company_id'       => $data->companyId ?? $this->defaultCompanyId(),
                'invoice_date'     => $data->invoiceDate,
                'due_date'         => $data->dueDate,
                'status'           => InvoiceStatus::Draft,
                'invoice_type'     => $data->invoiceType ?? 'tax',
                'transport_charge' => $data->transportCharge !== null
                    ? $this->money($data->transportCharge)
                    : $this->defaultTransportCharge($so),
                'delivery_address' => $data->deliveryAddress ?? $do->delivery_address,
                'remarks'          => $data->remarks,
                'mode_of_payment'  => $data->modeOfPayment,
                'created_by'       => Auth::id(),
                'subtotal'         => 0,
                'grand_total'      => 0,
            ]);

            foreach ($do->items as $doItem) {
                $soItem  = $doItem->soItem;
                $pricing = $this->linePricing($soItem, $invoice->invoice_type);
                $item    = new InvoiceItem([
                    'invoice_id'   => $invoice->id,
                    'so_item_id'   => $doItem->so_item_id,
                    'do_item_id'   => $doItem->id,
                    'product_id'   => $doItem->product_id,
                    'unit_id'      => $doItem->unit_id,
                    'price_unit_id' => $soItem?->price_unit_id,
                    'attribute_id' => $doItem->attribute_id,
                    'quantity'     => (float) $doItem->quantity,
                    'unit_price'   => $pricing['unit_price'],
                    'discount'     => (float) ($soItem?->discount ?? 0),
                    'tax'          => $pricing['tax'],
                ]);
                $this->recalculateLineTotal($item);
            }

            $this->applyItemOverrides($invoice, $data->items);
            $this->enforceInvoiceType($invoice);
            $this->recalculateTotals($invoice);

            return $this->find($invoice->id);
        });
    }

    /**
     * IRD invoice serial number: YYMMM_QQQQ_XXXXX
     *   YY    — last 2 digits of the invoice's calendar year
     *   MMM   — first 3 letters of the invoice's calendar month, uppercase
     *   QQQQ  — the billed customer's customer_code
     *   XXXXX — a single running serial, shared by every customer/branch, that
     *           never resets by month or year, zero-padded to 4 digits
     *           (0001, 0002 … 9999) then growing naturally past that (10000)
     */
    private function buildInvoiceNo(bool $lock, string $customerCode, ?string $invoiceDate): string
    {
        $date   = $invoiceDate ? \Illuminate\Support\Carbon::parse($invoiceDate) : now();
        $code   = str_replace(' ', '', $customerCode) ?: 'CUST';
        $serial = str_pad((string) $this->nextInvoiceSerial($lock), 4, '0', STR_PAD_LEFT);

        return $date->format('y') . strtoupper($date->format('M')) . '_' . $code . '_' . $serial;
    }

    /** The running XXXXX serial — global across every invoice, ever-increasing, never reset. */
    private function nextInvoiceSerial(bool $lock): int
    {
        $query = Invoice::withTrashed()->orderByDesc('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $last = $query->value('invoice_no');

        return ($last && preg_match('/(\d+)$/', $last, $m)) ? ((int) $m[1] + 1) : 1;
    }

    /** SO transport is billed once — on the SO's first non-cancelled invoice. */
    private function defaultTransportCharge(SalesOrder $so): float
    {
        $hasLiveInvoice = Invoice::where('so_id', $so->id)
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->exists();

        return $hasLiveInvoice ? 0.0 : $this->money((float) $so->transport_charge);
    }

    /**
     * A tax invoice has to name its supplier, so an invoice is never left without a
     * company. Where the caller does not say which, adopt the earliest — correct for
     * the single-company deployments, and re-pointable per invoice for the rest.
     */
    private function defaultCompanyId(): ?int
    {
        $id = Company::query()->orderBy('id')->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function doHasLiveInvoice(int $doId): bool
    {
        return Invoice::where('do_id', $doId)
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->exists();
    }

    private function soHasLiveDirectInvoice(int $soId): bool
    {
        return Invoice::where('so_id', $soId)
            ->whereNull('do_id')
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->exists();
    }

    private function soHasLivePerDoInvoice(int $soId): bool
    {
        return Invoice::where('so_id', $soId)
            ->whereNotNull('do_id')
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->exists();
    }

    /** Every invoice money amount — unit prices, totals, transport charge — rounds to 2dp before it is stored. */
    private function money(float $value): float
    {
        return round($value, self::MONEY_SCALE);
    }

    private function recalculateLineTotal(InvoiceItem $item): void
    {
        $item->unit_price = $this->money((float) $item->unit_price);

        $gross = (float) $item->quantity * $item->unit_price;

        $item->line_total = $this->money(
            $gross
                - ($gross * (float) $item->discount / 100)
                + ($gross * (float) $item->tax / 100)
        );

        $item->save();
    }

    private function recalculateTotals(Invoice $invoice): void
    {
        $invoice->refresh();
        $subtotal   = (float) $invoice->items()->sum(DB::raw('quantity * unit_price'));
        $lineTotals = (float) $invoice->items()->sum(DB::raw('line_total'));

        $invoice->update([
            'subtotal'    => $this->money($subtotal),
            'grand_total' => $this->money($lineTotals + (float) $invoice->transport_charge),
        ]);
    }

    /** @return array<string, mixed> */
    private function soSummary(SalesOrder $so): array
    {
        return [
            'id'               => $so->id,
            'so_no'            => $so->so_no,
            'status'           => $so->status->value,
            'transport_charge' => (float) $so->transport_charge,
            'delivery_address' => $so->delivery_address,
            'customer'         => $so->customer ? [
                'id'   => $so->customer->id,
                'name' => $so->customer->customer_name,
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function billingGuards(SalesOrder $so, bool $forDirect, ?DeliveryOrder $do = null): array
    {
        return [
            'so_confirmed_or_completed' => in_array($so->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::Completed]),
            'do_confirmed'              => $do ? $do->status === DeliveryOrderStatus::Confirmed : null,
            'do_already_invoiced'       => $do ? $this->doHasLiveInvoice($do->id) : null,
            'has_direct_invoice'        => $this->soHasLiveDirectInvoice($so->id),
            'has_per_do_invoices'       => $this->soHasLivePerDoInvoice($so->id),
            'blocked'                   => $forDirect
                ? $this->soHasLivePerDoInvoice($so->id) || $this->soHasLiveDirectInvoice($so->id)
                : ($do && ($this->doHasLiveInvoice($do->id) || $this->soHasLiveDirectInvoice($so->id))),
        ];
    }

    /** @return array<string, mixed> */
    private function itemPreview(?SalesOrderItem $soItem, float $quantity, ?int $doItemId): array
    {
        $taxPricing = $this->taxPricingForSoItem($soItem);

        return [
            'so_item_id' => $soItem?->id,
            'do_item_id' => $doItemId,
            'product'    => $soItem?->product ? [
                'id'           => $soItem->product->id,
                'name'         => $soItem->product->name,
                'product_code' => $soItem->product->product_code,
            ] : null,
            'unit'       => $soItem?->unit ? ['id' => $soItem->unit->id, 'name' => $soItem->unit->name] : null,
            'attribute'  => $soItem?->attribute ? ['id' => $soItem->attribute->id, 'name' => $soItem->attribute->attribute_name] : null,
            'quantity'   => $quantity,
            // Non-Tax invoice price: the SO price as-is (costing AFTER-tax, VAT inside)
            'unit_price' => $this->money((float) ($soItem?->unit_price ?? 0)),
            'discount'   => (float) ($soItem?->discount ?? 0),
            'tax'        => (float) ($soItem?->tax ?? 0),
            // Tax invoice price: costing BEFORE-tax + the VAT % to add per line
            'tax_unit_price' => $taxPricing['unit_price'],
            'tax_vat_pct'    => $taxPricing['tax'],
        ];
    }

    /**
     * What a line bills, by invoice type.
     *
     *   tax     — the costing's BEFORE-tax price plus the VAT % that costing used.
     *   non_tax — the SO price as-is (costing AFTER-tax, VAT already inside), 0 tax.
     *
     * @return array{unit_price: float, tax: float}
     */
    private function linePricing(?SalesOrderItem $soItem, string $invoiceType): array
    {
        if ($invoiceType === 'non_tax') {
            return ['unit_price' => $this->money((float) ($soItem?->unit_price ?? 0)), 'tax' => 0.0];
        }

        return $this->taxPricingForSoItem($soItem);
    }

    /**
     * The Tax-invoice price pair of an SO line: its rolls' confirmed-costing
     * before-tax price (restated per the LINE's unit) and the VAT % the costing
     * applied. When the rolls carry no costing — or disagree — the SO price has
     * the default VAT stripped out instead, so before-tax + VAT still lands on
     * the price the SO quoted.
     *
     * @return array{unit_price: float, tax: float}
     */
    private function taxPricingForSoItem(?SalesOrderItem $soItem): array
    {
        $soPrice  = (float) ($soItem?->unit_price ?? 0);
        $fallback = [
            'unit_price' => $this->money($soPrice / (1 + self::FALLBACK_VAT_PCT / 100)),
            'tax'        => self::FALLBACK_VAT_PCT,
        ];

        if ($soItem === null) {
            return $fallback;
        }

        // The GRN lines behind this SO line's allocated rolls
        $grnItemIds = DB::table('inv_sales_order_pieces as sp')
            ->join('inv_grn_item_pieces as p', 'p.id', '=', 'sp.piece_id')
            ->where('sp.so_item_id', $soItem->id)
            ->whereNotNull('p.grn_item_id')
            ->distinct()
            ->pluck('p.grn_item_id');

        $pairs = $grnItemIds
            ->map(fn ($id) => $this->pricing->costingPricingForGrnItem((int) $id))
            ->filter()
            ->unique(fn (array $p) => $p['before_tax_price'] . '|' . $p['vat_pct'])
            ->values();

        // Lines are split by selling price at allocation, so one price pair is the
        // normal case; none (uncosted) or several (shouldn't happen) → fallback.
        if ($pairs->count() !== 1) {
            return $fallback;
        }

        $pair = $pairs->first();

        // before_tax_price is per the stocking UOM; it must be restated per the unit the
        // line QUOTES its price in — price_unit_id when set (a yard line billing the
        // per-metre price keeps factor 1), else the quantity's unit (legacy lines).
        $factor     = 1.0;
        $unitId     = $soItem->price_unit_id !== null
            ? (int) $soItem->price_unit_id
            : ($soItem->unit_id !== null ? (int) $soItem->unit_id : null);
        $baseUnitId = Product::where('id', $soItem->product_id)->value('base_unit_type_id');

        if ($unitId !== null && $baseUnitId !== null && $unitId !== (int) $baseUnitId) {
            $rate = $this->units->tryFactor($unitId, (int) $baseUnitId);
            if ($rate === null || $rate <= 0.0) {
                return $fallback; // no rate — better the derived price than a wrong-unit one
            }
            $factor = $rate;
        }

        return [
            'unit_price' => $this->money($pair['before_tax_price'] * $factor),
            'tax'        => $pair['vat_pct'],
        ];
    }

    /**
     * Draft-stage per-line edits from the form (price/discount/tax/remarks) —
     * quantities always come from the source document.
     *
     * @param array<array{so_item_id:int, unit_price:?float, discount:?float, tax:?float, remarks:?string}> $items
     */
    private function applyItemOverrides(Invoice $invoice, array $items): void
    {
        if ($items === []) {
            return;
        }

        $overrides = collect($items)->keyBy('so_item_id');

        foreach ($invoice->items()->get() as $item) {
            $override = $overrides->get($item->so_item_id);
            if (!$override) {
                continue;
            }

            $item->unit_price = isset($override['unit_price']) ? $this->money((float) $override['unit_price']) : $item->unit_price;
            $item->discount   = isset($override['discount'])   ? (float) $override['discount']   : $item->discount;
            $item->tax        = isset($override['tax'])        ? (float) $override['tax']        : $item->tax;
            $item->remarks    = $override['remarks'] ?? $item->remarks;
            $this->recalculateLineTotal($item);
        }
    }

    /** A non-tax invoice never adds VAT — force Tax % to 0 whatever the form sent. */
    private function enforceInvoiceType(Invoice $invoice): void
    {
        if ($invoice->invoice_type !== 'non_tax') {
            return;
        }

        foreach ($invoice->items()->where('tax', '!=', 0)->get() as $item) {
            $item->tax = 0.0;
            $this->recalculateLineTotal($item);
        }
    }
}
