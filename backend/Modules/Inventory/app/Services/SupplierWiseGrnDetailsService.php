<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\GrnStatus;

class SupplierWiseGrnDetailsService
{
    /**
     * Hard ceiling on GRN-item rows returned in one report — transaction level like the
     * other detail reports, so it can grow fast without a tight date range.
     */
    private const MAX_ROWS = 5000;

    /**
     * Build the Supplier Wise GRN Details dataset: supplier → GRN → one row per GRN
     * item, with a total per GRN, an amount subtotal per supplier, and a grand total.
     * Shared by the JSON, PDF and CSV endpoints.
     *
     * Cost source is inv_goods_received_note_items, scoped to Confirmed GRNs only —
     * draft GRNs haven't posted to stock/spend yet. Filtered on GRN date (both bounds
     * required — this is transaction-level and can otherwise return an unbounded number
     * of rows). GRNs without a supplier (none in practice, but the column is nullable)
     * are excluded — a supplier-wise report has nowhere to place them.
     *
     * @param array{date_from:string, date_to:string, supplier_id?:int|null, product_id?:int|null} $filters
     * @return array<string, mixed>
     */
    public function build(array $filters): array
    {
        $supplierId = !empty($filters['supplier_id']) ? (int) $filters['supplier_id'] : null;
        $productId  = !empty($filters['product_id']) ? (int) $filters['product_id'] : null;

        // grn_date is a plain DATE column — compare as Y-m-d strings, no DATE() wrap, stays sargable.
        $fromDate = Carbon::parse($filters['date_from'])->toDateString();
        $toDate   = Carbon::parse($filters['date_to'])->toDateString();

        $rows = DB::table('inv_goods_received_note_items as gi')
            ->join('inv_goods_received_notes as g', 'g.id', '=', 'gi.grn_id')
            ->join('inv_products as p', 'p.id', '=', 'gi.product_id')
            ->join('inv_supplier_masters as sm', 'sm.id', '=', 'g.supplier_id')
            ->leftJoin('inv_purchase_orders as po', 'po.id', '=', 'g.po_id')
            ->leftJoin('inv_attributes as a', 'a.id', '=', 'gi.attribute_id')
            ->leftJoin('inv_unit_types as u', 'u.id', '=', 'gi.unit_id')
            ->where('g.status', GrnStatus::Confirmed->value)
            ->whereNull('g.deleted_at')
            ->where('g.grn_date', '>=', $fromDate)
            ->where('g.grn_date', '<=', $toDate)
            ->when($supplierId, fn ($q) => $q->where('g.supplier_id', $supplierId))
            ->when($productId, fn ($q) => $q->where('p.id', $productId))
            ->select([
                'sm.id as supplier_id', 'sm.supplier_name',
                'g.id as grn_id', 'g.grn_no', 'g.grn_date',
                'po.po_no',
                'p.product_code', 'p.name as product_name',
                'a.attribute_name',
                DB::raw('COALESCE(u.symbol, u.name) as unit_label'),
                'gi.quantity_received', 'gi.unit_price', 'gi.discount', 'gi.tax', 'gi.line_total',
            ])
            ->orderBy('sm.supplier_name')
            ->orderBy('g.grn_date')
            ->orderBy('g.grn_no')
            ->orderBy('p.name')
            ->limit(self::MAX_ROWS + 1)
            ->get();

        if ($rows->count() > self::MAX_ROWS) {
            abort(422, 'The selected filters return too many rows — narrow the date range.');
        }

        $suppliers   = [];
        $totalQty    = 0.0;
        $totalAmount = 0.0;

        foreach ($rows->groupBy('supplier_id') as $supId => $supRows) {
            $grns      = [];
            $supAmount = 0.0;

            foreach ($supRows->groupBy('grn_id') as $grnId => $grnRows) {
                $items     = [];
                $grnQty    = 0.0;
                $grnAmount = 0.0;

                foreach ($grnRows as $row) {
                    $qty    = (float) $row->quantity_received;
                    $amount = (float) $row->line_total;

                    $itemName = $row->product_name;
                    if ($row->attribute_name) {
                        $itemName .= ' - ' . $row->attribute_name;
                    }

                    $items[] = [
                        'product_code' => $row->product_code,
                        'item_name'    => $itemName,
                        'quantity'     => $qty,
                        'unit'         => $row->unit_label,
                        'unit_price'   => (float) $row->unit_price,
                        'discount'     => (float) $row->discount,
                        'tax'          => (float) $row->tax,
                        'amount'       => $amount,
                    ];

                    $grnQty    += $qty;
                    $grnAmount += $amount;
                }

                $grns[] = [
                    'grn_id'     => $grnId,
                    'grn_no'     => $grnRows->first()->grn_no,
                    'grn_date'   => $grnRows->first()->grn_date,
                    'po_no'      => $grnRows->first()->po_no,
                    'items'      => $items,
                    'total_qty'  => $grnQty,
                    'grn_amount' => $grnAmount,
                ];

                $supAmount += $grnAmount;
                $totalQty  += $grnQty;
            }

            $suppliers[] = [
                'supplier_id'     => $supId,
                'supplier_name'   => $supRows->first()->supplier_name,
                'grns'            => $grns,
                'supplier_amount' => $supAmount,
            ];

            $totalAmount += $supAmount;
        }

        $summary = [
            'total_qty'    => $totalQty,
            'total_amount' => $totalAmount,
        ];

        return [
            'header'    => $this->buildHeader($supplierId, $productId, $filters['date_from'], $filters['date_to'], $summary),
            'suppliers' => $suppliers,
            'summary'   => $summary,
        ];
    }

    /**
     * @param array<string, float> $summary
     * @return array<string, mixed>
     */
    private function buildHeader(?int $supplierId, ?int $productId, string $dateFrom, string $dateTo, array $summary): array
    {
        $supplier = $supplierId ? DB::table('inv_supplier_masters')->where('id', $supplierId)->first(['supplier_name']) : null;
        $product  = $productId ? DB::table('inv_products')->where('id', $productId)->first(['name']) : null;

        // Single-tenant deployment: the report always belongs to the one primary company.
        $company = DB::table('inv_companies')->orderBy('id')->first();

        return [
            'company_name'    => $company->company_name ?? null,
            'company_address' => $company
                ? collect([$company->street_address, $company->city, $company->state, $company->postal_zip_code])->filter()->implode(', ')
                : null,
            'company_email'   => $company->company_email ?? null,
            'supplier_name'   => $supplier->supplier_name ?? null,
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
