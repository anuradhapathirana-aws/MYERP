<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Models\Company;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\ProformaInvoiceData;
use Modules\Inventory\Enums\InvoiceStatus;
use Modules\Inventory\Enums\ProformaInvoiceStatus;
use Modules\Inventory\Enums\SalesOrderStatus;
use Modules\Inventory\Models\Invoice;
use Modules\Inventory\Models\ProformaInvoice;
use Modules\Inventory\Models\ProformaInvoiceItem;
use Modules\Inventory\Models\SalesOrder;
use Modules\Inventory\Models\SalesOrderItem;

/**
 * Proforma invoices — a priced, non-binding offer raised from a confirmed sales
 * order before delivery, for the full SO quantity. No stock, receivable or
 * ledger effect.
 *
 * Lines are locked to the SO: quantity, price and discount come from the SO
 * line and the tax/non-tax pricing is the exact one a DO invoice would bill
 * (InvoiceService::linePricing). A draft re-syncs its lines from the SO on
 * every save; an issued proforma is a frozen quote that later DO invoices of
 * the SO auto-link to and bill (see InvoiceService::createFromDo).
 */
class ProformaInvoiceService
{
    /** Default offer validity when the form leaves valid_until empty. */
    private const DEFAULT_VALID_DAYS = 14;

    private const MONEY_SCALE = 2;

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly ProformaInvoicingTracker $tracker,
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
            $query->whereDate('proforma_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('proforma_date', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    /** One proforma with its lines, linked DO invoices and the quantity each line has been invoiced. */
    public function find(int $id): ProformaInvoice
    {
        $proforma = ProformaInvoice::with([
            'items.product',
            'items.unit',
            'items.priceUnit',
            'items.attribute',
            'salesOrder',
            'customer',
            'company',
            'invoices' => fn ($q) => $q->with('deliveryOrder:id,do_no')->orderBy('id'),
        ])->findOrFail($id);

        $invoiced = $this->tracker->invoicedQuantities($proforma->id);
        foreach ($proforma->items as $item) {
            $item->setAttribute('invoiced_quantity', (float) ($invoiced[$item->id] ?? 0));
        }

        return $proforma;
    }

    /** Lock-free preview; the real number is generated inside the create transaction. */
    public function nextProformaNo(?int $soId, ?string $proformaDate): ?string
    {
        if ($soId === null) {
            return null;
        }

        $so = SalesOrder::with('customer')->find($soId);
        if (!$so) {
            return null;
        }

        return $this->buildProformaNo(
            lock: false,
            customerCode: $so->customer?->customer_code ?? 'CUST',
            proformaDate: $proformaDate,
        );
    }

    /**
     * Preview of what a proforma for this SO will quote, for both invoice types.
     *
     * @return array<string, mixed>
     */
    public function sourceForSo(int $soId): array
    {
        $so = SalesOrder::with(['items.product', 'items.unit', 'items.priceUnit', 'items.attribute', 'customer'])
            ->findOrFail($soId);

        $live = $this->liveProformaForSo($so->id);

        return [
            'sales_order' => [
                'id'               => $so->id,
                'so_no'            => $so->so_no,
                'status'           => $so->status->value,
                'transport_charge' => $this->money((float) $so->transport_charge),
                'delivery_address' => $so->delivery_address,
                'customer'         => $so->customer ? [
                    'id'            => $so->customer->id,
                    'customer_code' => $so->customer->customer_code,
                    'name'          => $so->customer->customer_name,
                ] : null,
            ],
            'guards' => [
                'so_confirmed'     => $so->status === SalesOrderStatus::Confirmed,
                'has_live_proforma' => $live !== null,
                'live_proforma'    => $live ? ['id' => $live->id, 'proforma_no' => $live->proforma_no] : null,
                'has_invoices'     => $this->soHasLiveInvoice($so->id),
            ],
            'default_invoice_type' => $this->defaultInvoiceType($so),
            'items' => $so->items->map(function (SalesOrderItem $soItem): array {
                $nonTax = $this->invoices->linePricing($soItem, 'non_tax');
                $tax    = $this->invoices->linePricing($soItem, 'tax');

                return [
                    'so_item_id' => $soItem->id,
                    'product'    => $soItem->product ? [
                        'id'           => $soItem->product->id,
                        'name'         => $soItem->product->name,
                        'product_code' => $soItem->product->product_code,
                    ] : null,
                    'unit'       => $soItem->unit ? ['id' => $soItem->unit->id, 'name' => $soItem->unit->name] : null,
                    'price_unit' => $soItem->priceUnit ? ['id' => $soItem->priceUnit->id, 'name' => $soItem->priceUnit->name, 'symbol' => $soItem->priceUnit->symbol] : null,
                    'attribute'  => $soItem->attribute ? ['id' => $soItem->attribute->id, 'name' => $soItem->attribute->attribute_name] : null,
                    'quantity'   => (float) $soItem->quantity,
                    'discount'   => (float) $soItem->discount,
                    'unit_price'     => $nonTax['unit_price'],
                    'tax_unit_price' => $tax['unit_price'],
                    'tax_vat_pct'    => $tax['tax'],
                ];
            })->values()->all(),
        ];
    }

    public function create(ProformaInvoiceData $data): ProformaInvoice
    {
        return DB::transaction(function () use ($data): ProformaInvoice {
            $so = SalesOrder::with('customer')->whereKey($data->soId)->lockForUpdate()->firstOrFail();

            if ($so->status !== SalesOrderStatus::Confirmed) {
                abort(422, 'A proforma invoice can only be raised from a confirmed sales order.');
            }

            if ($live = $this->liveProformaForSo($so->id)) {
                abort(422, "Sales order {$so->so_no} already has proforma {$live->proforma_no}. Cancel it to raise a new one.");
            }

            // A proforma quotes before billing — DO invoices raised earlier could never link to it.
            if ($this->soHasLiveInvoice($so->id)) {
                abort(422, "Sales order {$so->so_no} is already invoiced — a proforma must be raised before invoicing.");
            }

            $proforma = ProformaInvoice::create([
                'proforma_no'      => $this->buildProformaNo(
                    lock: true,
                    customerCode: $so->customer?->customer_code ?? 'CUST',
                    proformaDate: $data->proformaDate,
                ),
                'source_type'      => ProformaInvoice::SOURCE_SALES_ORDER,
                'source_id'        => $so->id,
                'so_id'            => $so->id,
                'customer_id'      => $so->customer_id,
                'company_id'       => $data->companyId ?? $this->defaultCompanyId(),
                'proforma_date'    => $data->proformaDate,
                'valid_until'      => $data->validUntil
                    ?? Carbon::parse($data->proformaDate)->addDays(self::DEFAULT_VALID_DAYS)->toDateString(),
                'payment_terms'    => $data->paymentTerms,
                'invoice_type'     => $data->invoiceType ?? $this->defaultInvoiceType($so),
                'status'           => ProformaInvoiceStatus::Draft,
                'transport_charge' => $this->money((float) $so->transport_charge),
                'delivery_address' => $data->deliveryAddress ?? $so->delivery_address,
                'remarks'          => $data->remarks,
                'created_by'       => Auth::id(),
                'subtotal'         => 0,
                'grand_total'      => 0,
            ]);

            $this->syncLinesFromSo($proforma, $so);

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

            $so = SalesOrder::findOrFail($proforma->so_id);

            $proforma->update([
                'proforma_date'    => $data->proformaDate,
                'valid_until'      => $data->validUntil,
                'payment_terms'    => $data->paymentTerms,
                'invoice_type'     => $data->invoiceType ?? $proforma->invoice_type,
                'company_id'       => $data->companyId ?? $proforma->company_id,
                'delivery_address' => $data->deliveryAddress,
                'remarks'          => $data->remarks,
                'transport_charge' => $this->money((float) $so->transport_charge),
            ]);

            // Prices are locked to the SO — a draft always re-quotes the SO as it is now.
            $this->syncLinesFromSo($proforma, $so);

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

        if (
            $newStatus === ProformaInvoiceStatus::Cancelled
            && Invoice::where('proforma_id', $proforma->id)
                ->where('status', '!=', InvoiceStatus::Cancelled->value)
                ->exists()
        ) {
            abort(422, 'This proforma is linked to invoices and cannot be cancelled.');
        }

        $proforma->update([
            'status'       => $newStatus,
            'issued_at'    => $newStatus === ProformaInvoiceStatus::Issued ? now() : $proforma->issued_at,
            'cancelled_at' => $newStatus === ProformaInvoiceStatus::Cancelled ? now() : $proforma->cancelled_at,
        ]);

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

    /** Rebuilds every line from the SO — quantity, price, discount and the type's VAT. */
    private function syncLinesFromSo(ProformaInvoice $proforma, SalesOrder $so): void
    {
        $proforma->items()->delete();

        $subtotal   = 0.0;
        $lineTotals = 0.0;

        foreach ($so->items()->get() as $soItem) {
            $pricing = $this->invoices->linePricing($soItem, $proforma->invoice_type);
            $tax     = $proforma->invoice_type === 'non_tax' ? 0.0 : (float) $pricing['tax'];
            $price   = $this->money((float) $pricing['unit_price']);
            $gross   = (float) $soItem->quantity * $price;

            $lineTotal = $this->money(
                $gross
                    - ($gross * (float) $soItem->discount / 100)
                    + ($gross * $tax / 100)
            );

            ProformaInvoiceItem::create([
                'proforma_id'      => $proforma->id,
                'source_item_type' => ProformaInvoiceItem::SOURCE_SALES_ORDER_ITEM,
                'source_item_id'   => $soItem->id,
                'so_item_id'       => $soItem->id,
                'product_id'       => $soItem->product_id,
                'unit_id'          => $soItem->unit_id,
                'price_unit_id'    => $soItem->price_unit_id,
                'attribute_id'     => $soItem->attribute_id,
                'quantity'         => (float) $soItem->quantity,
                'unit_price'       => $price,
                'discount'         => (float) $soItem->discount,
                'tax'              => $tax,
                'line_total'       => $lineTotal,
                'remarks'          => $soItem->remarks,
            ]);

            $subtotal   += $gross;
            $lineTotals += $lineTotal;
        }

        $proforma->update([
            'subtotal'    => $this->money($subtotal),
            'grand_total' => $this->money($lineTotals + (float) $proforma->transport_charge),
        ]);
    }

    /** The SO's non-cancelled proforma — at most one exists. */
    private function liveProformaForSo(int $soId): ?ProformaInvoice
    {
        return ProformaInvoice::where('so_id', $soId)
            ->where('status', '!=', ProformaInvoiceStatus::Cancelled->value)
            ->first();
    }

    private function soHasLiveInvoice(int $soId): bool
    {
        return Invoice::where('so_id', $soId)
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->exists();
    }

    /**
     * PI-YYMMM_QQQQ_XXXX — mirrors the invoice number: invoice month, the customer's
     * code and one global running serial that never resets.
     */
    private function buildProformaNo(bool $lock, string $customerCode, ?string $proformaDate): string
    {
        $date   = $proformaDate ? Carbon::parse($proformaDate) : now();
        $code   = str_replace(' ', '', $customerCode) ?: 'CUST';
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

    /** Same default as the invoice: a customer with a TIN is quoted tax prices. */
    private function defaultInvoiceType(SalesOrder $so): string
    {
        return trim((string) $so->customer?->customer_tin) !== '' ? 'tax' : 'non_tax';
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
