<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\InvoiceStatus;
use Modules\Inventory\Models\Location;
use Modules\Inventory\Models\StockReferenceType;

class SalesByItemSummaryService
{
    /**
     * Hard ceiling on product+colour rows returned in one report — protects the JSON
     * payload and DomPDF rendering. Hit only when the catalogue/date range is huge
     * and unfiltered.
     */
    private const MAX_ROWS = 5000;

    /**
     * Build the Sales By Item Summary dataset: category-wise product+colour rows with
     * total sold quantity and amount, plus a header block and grand-total summary.
     * Shared by the JSON, PDF and CSV endpoints so the aggregation logic exists once.
     *
     * Revenue source is inv_invoice_items (billed amount, post discount/tax), scoped to
     * Issued and Paid invoices only — draft and cancelled invoices don't count.
     * "Date" filters on the invoice date. "Location" filters via the stock ledger
     * (inv_stock_transactions, reference_type=sales_delivery) rather than
     * inv_delivery_orders.location_id — that header column is routinely NULL for
     * roll/piece-based deliveries (location lives per piece instead), while the ledger
     * row written at DO confirm always carries the real location. Invoices with no
     * linked DO (direct-SO advance billing) are excluded only when a location filter is
     * applied — they still count toward the unfiltered total.
     *
     * @param array{date_from?:string|null, date_to?:string|null, location_id?:int|null, category_id?:int|null} $filters
     * @return array<string, mixed>
     */
    public function build(array $filters): array
    {
        $locationId = !empty($filters['location_id']) ? (int) $filters['location_id'] : null;
        $categoryId = !empty($filters['category_id']) ? (int) $filters['category_id'] : null;
        $dateFrom   = $filters['date_from'] ?? null;
        $dateTo     = $filters['date_to'] ?? null;

        // invoice_date is a plain DATE column — compare as Y-m-d strings, no DATE() wrap, stays sargable.
        $fromDate = $dateFrom ? Carbon::parse($dateFrom)->toDateString() : null;
        $toDate   = $dateTo ? Carbon::parse($dateTo)->toDateString() : null;

        $aggregates = DB::table('inv_invoice_items as ii')
            ->join('inv_invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->join('inv_products as p', 'p.id', '=', 'ii.product_id')
            ->join('inv_categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('inv_attributes as a', 'a.id', '=', 'ii.attribute_id')
            ->leftJoin('inv_unit_types as u', 'u.id', '=', 'ii.unit_id')
            ->whereIn('i.status', [InvoiceStatus::Issued->value, InvoiceStatus::Paid->value])
            ->when($fromDate, fn ($q) => $q->where('i.invoice_date', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->where('i.invoice_date', '<=', $toDate))
            ->when($locationId, fn ($q) => $q->whereExists(function ($sub) use ($locationId) {
                $sub->selectRaw('1')
                    ->from('inv_stock_transactions as st')
                    ->where('st.reference_type', StockReferenceType::CODE_SALES_DELIVERY)
                    ->whereColumn('st.reference_id', 'i.do_id')
                    ->whereColumn('st.product_id', 'ii.product_id')
                    ->whereRaw('st.attribute_id <=> ii.attribute_id') // null-safe equal — colourless products carry NULL on both sides
                    ->where('st.location_id', $locationId);
            }))
            ->when($categoryId, fn ($q) => $q->where('p.category_id', $categoryId))
            ->selectRaw(
                'c.id as category_id,
                 c.category_name,
                 p.id as product_id,
                 p.name as product_name,
                 p.product_code,
                 a.id as attribute_id,
                 a.attribute_name,
                 ii.unit_id,
                 COALESCE(u.symbol, u.name) as unit_label,
                 SUM(ii.quantity) as qty,
                 SUM(ii.line_total) as amount'
            )
            ->groupBy(
                'c.id', 'c.category_name',
                'p.id', 'p.name', 'p.product_code',
                'a.id', 'a.attribute_name',
                'ii.unit_id', 'u.symbol', 'u.name'
            )
            ->orderBy('c.category_name')
            ->orderBy('p.name')
            ->orderBy('a.attribute_name')
            ->limit(self::MAX_ROWS + 1)
            ->get();

        if ($aggregates->count() > self::MAX_ROWS) {
            abort(422, 'The selected filters return too many rows — narrow the filters.');
        }

        $categories  = [];
        $totalQty    = 0.0;
        $totalAmount = 0.0;

        foreach ($aggregates->groupBy('category_id') as $catId => $group) {
            $items     = [];
            $catQty    = 0.0;
            $catAmount = 0.0;

            foreach ($group as $agg) {
                $qty    = (float) $agg->qty;
                $amount = (float) $agg->amount;

                $items[] = [
                    'product_id'     => $agg->product_id,
                    'product_code'   => $agg->product_code,
                    'product_name'   => $agg->product_name,
                    'attribute_name' => $agg->attribute_name,
                    'unit'           => $agg->unit_label,
                    'quantity'       => $qty,
                    'amount'         => $amount,
                ];

                $catQty    += $qty;
                $catAmount += $amount;
            }

            $categories[] = [
                'category_id'     => $catId,
                'category_name'   => $group->first()->category_name,
                'items'           => $items,
                'category_qty'    => $catQty,
                'category_amount' => $catAmount,
            ];

            $totalQty    += $catQty;
            $totalAmount += $catAmount;
        }

        $summary = [
            'total_qty'    => $totalQty,
            'total_amount' => $totalAmount,
        ];

        return [
            'header'     => $this->buildHeader($locationId, $filters['category_id'] ?? null, $dateFrom, $dateTo, $summary),
            'categories' => $categories,
            'summary'    => $summary,
        ];
    }

    /**
     * @param array<string, float> $summary
     * @return array<string, mixed>
     */
    private function buildHeader(?int $locationId, mixed $categoryIdRaw, ?string $dateFrom, ?string $dateTo, array $summary): array
    {
        $categoryId = !empty($categoryIdRaw) ? (int) $categoryIdRaw : null;

        $location = $locationId ? Location::with('company')->find($locationId) : null;
        $category = $categoryId ? DB::table('inv_categories')->where('id', $categoryId)->first(['category_name']) : null;

        // Single-tenant deployment: without a location filter, fall back to the primary company.
        $company = $location?->company
            ?? DB::table('inv_companies')->orderBy('id')->first();

        return [
            'company_name'    => $company->company_name ?? null,
            'company_address' => $company
                ? collect([$company->street_address, $company->city, $company->state, $company->postal_zip_code])->filter()->implode(', ')
                : null,
            'company_email'   => $company->company_email ?? null,
            'location_name'   => $location?->location_name,
            'category_name'   => $category->category_name ?? null,
            'total_qty'       => $summary['total_qty'],
            'total_amount'    => $summary['total_amount'],
            'date_from'       => $dateFrom,
            'date_to'         => $dateTo,
            'generated_by'    => Auth::user()?->name,
            'generated_at'    => now()->toDateTimeString(),
        ];
    }
}
