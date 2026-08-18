<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\InvoiceStatus;
use Modules\Inventory\Models\Location;
use Modules\Inventory\Models\StockReferenceType;

class SalesByCustomerDetailsService
{
    /**
     * Hard ceiling on invoice-line rows returned in one report — transaction level like
     * the Item Detail Summary report, so it can grow fast without a tight date range.
     */
    private const MAX_ROWS = 5000;

    /**
     * Build the Sales By Customer Details dataset: customer → one row per invoice line
     * (transaction), with an amount subtotal per customer and a grand total. Shared by
     * the JSON, PDF and CSV endpoints.
     *
     * Revenue source is inv_invoice_items, scoped to Paid invoices only, filtered on
     * invoice date (both bounds required — this is transaction-level and can otherwise
     * return an unbounded number of rows). "Item Details" combines the product name,
     * colour/attribute and roll number(s) into a single column, matching the reference
     * layout. Roll numbers come from inv_grn_item_pieces via inv_delivery_order_pieces
     * (do_item_id) — one invoice line can carry several rolls, so they are aggregated
     * with GROUP_CONCAT rather than fanning the row out per piece.
     *
     * @param array{date_from:string, date_to:string, location_id?:int|null, customer_id?:int|null, product_id?:int|null} $filters
     * @return array<string, mixed>
     */
    public function build(array $filters): array
    {
        $locationId = !empty($filters['location_id']) ? (int) $filters['location_id'] : null;
        $customerId = !empty($filters['customer_id']) ? (int) $filters['customer_id'] : null;
        $productId  = !empty($filters['product_id']) ? (int) $filters['product_id'] : null;

        // invoice_date is a plain DATE column — compare as Y-m-d strings, no DATE() wrap, stays sargable.
        $fromDate = Carbon::parse($filters['date_from'])->toDateString();
        $toDate   = Carbon::parse($filters['date_to'])->toDateString();

        $rollNoSub = DB::table('inv_delivery_order_pieces as dop')
            ->join('inv_grn_item_pieces as gip', 'gip.id', '=', 'dop.piece_id')
            ->whereNotNull('gip.roll_no')
            ->groupBy('dop.do_item_id')
            ->selectRaw('dop.do_item_id, GROUP_CONCAT(DISTINCT gip.roll_no ORDER BY gip.roll_no SEPARATOR ", ") as roll_nos');

        $rows = DB::table('inv_invoice_items as ii')
            ->join('inv_invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->join('inv_products as p', 'p.id', '=', 'ii.product_id')
            ->join('inv_customer_masters as cu', 'cu.id', '=', 'i.customer_id')
            ->leftJoin('inv_attributes as a', 'a.id', '=', 'ii.attribute_id')
            ->leftJoin('inv_unit_types as u', 'u.id', '=', 'ii.unit_id')
            ->leftJoinSub($rollNoSub, 'rn', fn ($j) => $j->on('rn.do_item_id', '=', 'ii.do_item_id'))
            ->where('i.status', InvoiceStatus::Paid->value)
            ->where('i.invoice_date', '>=', $fromDate)
            ->where('i.invoice_date', '<=', $toDate)
            ->when($customerId, fn ($q) => $q->where('i.customer_id', $customerId))
            ->when($productId, fn ($q) => $q->where('p.id', $productId))
            ->when($locationId, fn ($q) => $q->whereExists(function ($sub) use ($locationId) {
                $sub->selectRaw('1')
                    ->from('inv_stock_transactions as st')
                    ->where('st.reference_type', StockReferenceType::CODE_SALES_DELIVERY)
                    ->whereColumn('st.reference_id', 'i.do_id')
                    ->whereColumn('st.product_id', 'ii.product_id')
                    ->whereRaw('st.attribute_id <=> ii.attribute_id') // null-safe equal — colourless products carry NULL on both sides
                    ->where('st.location_id', $locationId);
            }))
            ->select([
                'cu.id as customer_id', 'cu.customer_name',
                'p.name as product_name',
                'a.attribute_name',
                DB::raw('COALESCE(u.symbol, u.name) as unit_label'),
                'i.invoice_no', 'i.invoice_date',
                'ii.quantity', 'ii.unit_price', 'ii.line_total',
                'rn.roll_nos',
            ])
            ->orderBy('cu.customer_name')
            ->orderBy('i.invoice_date')
            ->orderBy('i.invoice_no')
            ->limit(self::MAX_ROWS + 1)
            ->get();

        if ($rows->count() > self::MAX_ROWS) {
            abort(422, 'The selected filters return too many rows — narrow the date range.');
        }

        $customers   = [];
        $totalQty    = 0.0;
        $totalAmount = 0.0;

        foreach ($rows->groupBy('customer_id') as $custId => $custRows) {
            $transactions = [];
            $custAmount   = 0.0;

            foreach ($custRows as $row) {
                $qty    = (float) $row->quantity;
                $amount = (float) $row->line_total;

                $itemDetails = $row->product_name;
                if ($row->attribute_name) {
                    $itemDetails .= ' - ' . $row->attribute_name;
                }
                if ($row->roll_nos) {
                    $itemDetails .= ' (Roll: ' . $row->roll_nos . ')';
                }

                $transactions[] = [
                    'type'         => 'Invoice',
                    'date'         => $row->invoice_date,
                    'number'       => $row->invoice_no,
                    'item_details' => $itemDetails,
                    'unit'         => $row->unit_label,
                    'quantity'     => $qty,
                    'price'        => (float) $row->unit_price,
                    'amount'       => $amount,
                ];

                $custAmount += $amount;
                $totalQty   += $qty;
            }

            $customers[] = [
                'customer_id'     => $custId,
                'customer_name'   => $custRows->first()->customer_name,
                'transactions'    => $transactions,
                'customer_amount' => $custAmount,
            ];

            $totalAmount += $custAmount;
        }

        $summary = [
            'total_qty'    => $totalQty,
            'total_amount' => $totalAmount,
        ];

        return [
            'header'    => $this->buildHeader($locationId, $customerId, $productId, $filters['date_from'], $filters['date_to'], $summary),
            'customers' => $customers,
            'summary'   => $summary,
        ];
    }

    /**
     * @param array<string, float> $summary
     * @return array<string, mixed>
     */
    private function buildHeader(?int $locationId, ?int $customerId, ?int $productId, string $dateFrom, string $dateTo, array $summary): array
    {
        $location = $locationId ? Location::with('company')->find($locationId) : null;
        $customer = $customerId ? DB::table('inv_customer_masters')->where('id', $customerId)->first(['customer_name']) : null;
        $product  = $productId ? DB::table('inv_products')->where('id', $productId)->first(['name']) : null;

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
            'customer_name'   => $customer->customer_name ?? null,
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
