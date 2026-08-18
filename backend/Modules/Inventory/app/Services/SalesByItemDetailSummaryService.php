<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\InvoiceStatus;

class SalesByItemDetailSummaryService
{
    /**
     * Hard ceiling on invoice-line rows returned in one report — this is transaction
     * level (unlike the summary report, nothing here is pre-aggregated), so it can grow
     * fast without a tight date range. Protects the JSON payload and DomPDF rendering.
     */
    private const MAX_ROWS = 5000;

    /**
     * Build the Sales By Item Detail Summary dataset: category → product → one row per
     * invoice line (transaction), with a subtotal per product, an amount subtotal per
     * category, and a grand total. Shared by the JSON, PDF and CSV endpoints.
     *
     * Revenue source is inv_invoice_items, scoped to Paid invoices only, filtered on
     * invoice date (both bounds required — this report is transaction-level and can
     * otherwise return an unbounded number of rows). Unlike the summary report this
     * does not group same-product lines together, so a customer who bought the same
     * product on three different invoices appears as three separate rows — that is the
     * point of a "detail" report.
     *
     * @param array{date_from:string, date_to:string, category_id?:int|null, product_id?:int|null} $filters
     * @return array<string, mixed>
     */
    public function build(array $filters): array
    {
        $categoryId = !empty($filters['category_id']) ? (int) $filters['category_id'] : null;
        $productId  = !empty($filters['product_id']) ? (int) $filters['product_id'] : null;

        // invoice_date is a plain DATE column — compare as Y-m-d strings, no DATE() wrap, stays sargable.
        $fromDate = Carbon::parse($filters['date_from'])->toDateString();
        $toDate   = Carbon::parse($filters['date_to'])->toDateString();

        $rows = DB::table('inv_invoice_items as ii')
            ->join('inv_invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->join('inv_products as p', 'p.id', '=', 'ii.product_id')
            ->join('inv_categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('inv_attributes as a', 'a.id', '=', 'ii.attribute_id')
            ->leftJoin('inv_unit_types as u', 'u.id', '=', 'ii.unit_id')
            ->leftJoin('inv_customer_masters as cu', 'cu.id', '=', 'i.customer_id')
            ->where('i.status', InvoiceStatus::Paid->value)
            ->where('i.invoice_date', '>=', $fromDate)
            ->where('i.invoice_date', '<=', $toDate)
            ->when($categoryId, fn ($q) => $q->where('p.category_id', $categoryId))
            ->when($productId, fn ($q) => $q->where('p.id', $productId))
            ->select([
                'c.id as category_id', 'c.category_name',
                'p.id as product_id', 'p.name as product_name',
                'a.attribute_name',
                DB::raw('COALESCE(u.symbol, u.name) as unit_label'),
                'i.invoice_no', 'i.invoice_date',
                'cu.customer_name',
                'ii.quantity', 'ii.unit_price', 'ii.line_total',
            ])
            ->orderBy('c.category_name')
            ->orderBy('p.name')
            ->orderBy('i.invoice_date')
            ->orderBy('i.invoice_no')
            ->limit(self::MAX_ROWS + 1)
            ->get();

        if ($rows->count() > self::MAX_ROWS) {
            abort(422, 'The selected filters return too many rows — narrow the date range.');
        }

        $categories  = [];
        $totalQty    = 0.0;
        $totalAmount = 0.0;

        foreach ($rows->groupBy('category_id') as $catId => $catRows) {
            $products  = [];
            $catAmount = 0.0;

            foreach ($catRows->groupBy('product_id') as $prodId => $prodRows) {
                $transactions = [];
                $prodQty      = 0.0;
                $prodAmount   = 0.0;

                foreach ($prodRows as $row) {
                    $qty    = (float) $row->quantity;
                    $amount = (float) $row->line_total;

                    $transactions[] = [
                        'type'           => 'Invoice',
                        'date'           => $row->invoice_date,
                        'number'         => $row->invoice_no,
                        'customer_name'  => $row->customer_name,
                        'attribute_name' => $row->attribute_name,
                        'unit'           => $row->unit_label,
                        'quantity'       => $qty,
                        'price'          => (float) $row->unit_price,
                        'amount'         => $amount,
                    ];

                    $prodQty    += $qty;
                    $prodAmount += $amount;
                }

                $products[] = [
                    'product_id'      => $prodId,
                    'product_name'    => $prodRows->first()->product_name,
                    'transactions'    => $transactions,
                    'product_qty'     => $prodQty,
                    'product_amount'  => $prodAmount,
                ];

                $catAmount += $prodAmount;
                $totalQty  += $prodQty;
            }

            $categories[] = [
                'category_id'     => $catId,
                'category_name'   => $catRows->first()->category_name,
                'products'        => $products,
                'category_amount' => $catAmount,
            ];

            $totalAmount += $catAmount;
        }

        $summary = [
            'total_qty'    => $totalQty,
            'total_amount' => $totalAmount,
        ];

        return [
            'header'     => $this->buildHeader($categoryId, $productId, $filters['date_from'], $filters['date_to'], $summary),
            'categories' => $categories,
            'summary'    => $summary,
        ];
    }

    /**
     * @param array<string, float> $summary
     * @return array<string, mixed>
     */
    private function buildHeader(?int $categoryId, ?int $productId, string $dateFrom, string $dateTo, array $summary): array
    {
        $category = $categoryId ? DB::table('inv_categories')->where('id', $categoryId)->first(['category_name']) : null;
        $product  = $productId ? DB::table('inv_products')->where('id', $productId)->first(['name']) : null;

        // Single-tenant deployment: the report always belongs to the one primary company.
        $company = DB::table('inv_companies')->orderBy('id')->first();

        return [
            'company_name'    => $company->company_name ?? null,
            'company_address' => $company
                ? collect([$company->street_address, $company->city, $company->state, $company->postal_zip_code])->filter()->implode(', ')
                : null,
            'company_email'   => $company->company_email ?? null,
            'category_name'   => $category->category_name ?? null,
            'product_name'    => $product->name ?? null,
            'total_qty'       => $summary['total_qty'],
            'total_amount'    => $summary['total_amount'],
            'date_from'       => $dateFrom,
            'date_to'         => $dateTo,
            'generated_by'    => Auth::user()?->name,
            'generated_at'    => now()->toDateTimeString(),
        ];
    }
}
