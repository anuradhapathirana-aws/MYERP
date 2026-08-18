<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Services\BinCardService;
use Modules\Inventory\Services\ItemSearchReportService;
use Modules\Inventory\Services\OutstandingSummaryReportService;
use Modules\Inventory\Services\SalesByCustomerDetailsService;
use Modules\Inventory\Services\SalesByItemDetailSummaryService;
use Modules\Inventory\Services\SalesByItemSummaryService;
use Modules\Inventory\Services\SalesSummaryReportService;
use Modules\Inventory\Services\StockMovementSummaryService;
use Modules\Inventory\Services\SupplierWiseGrnDetailsService;

class ReportController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:view_reports');
    }

    // ── 1. Current Stock Levels ───────────────────────────────────────────────
    // Reads the running balance cache (inv_product_location_stores), which is
    // atomically updated on every GRN confirmation. O(rows in cache) — stays
    // fast regardless of transaction history depth.
    public function stockLevels(Request $request): JsonResponse
    {
        $perPage = 50;

        $query = DB::table('inv_product_location_stores as pls')
            ->join('inv_products as p', 'p.id', '=', 'pls.product_id')
            ->join('inv_locations as l', 'l.id', '=', 'pls.location_id')
            ->join('inv_stores as s', 's.id', '=', 'pls.store_id')
            ->leftJoin('inv_categories as c', 'c.id', '=', 'p.category_id')
            ->where('pls.current_stock', '>', 0)
            ->select([
                'p.id as product_id',
                'p.product_code',
                'p.name as product_name',
                'p.display_name',
                'p.product_type',
                'c.category_name',
                'pls.location_id',
                'l.location_name',
                'pls.store_id',
                's.store_name',
                'pls.current_stock',
                'p.reorder_level',
                'p.reorder_qty',
            ]);

        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('p.name', 'like', $search)
                  ->orWhere('p.product_code', 'like', $search);
            });
        }

        if ($request->filled('product_id')) {
            $query->where('pls.product_id', $request->integer('product_id'));
        }

        if ($request->filled('location_id')) {
            $query->where('pls.location_id', $request->integer('location_id'));
        }

        if ($request->filled('store_id')) {
            $query->where('pls.store_id', $request->integer('store_id'));
        }

        if ($request->filled('category_id')) {
            $query->where('p.category_id', $request->integer('category_id'));
        }

        if ($request->input('stock_status') === 'below_reorder') {
            $query->whereRaw('pls.current_stock <= p.reorder_level AND p.reorder_level > 0');
        } elseif ($request->input('stock_status') === 'out_of_stock') {
            $query->where('pls.current_stock', '<=', 0);
        }

        $totalStock = (clone $query)->sum('pls.current_stock');

        $paginator = $query->orderBy('p.name')->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
            'summary' => [
                'total_stock' => (float) $totalStock,
            ],
        ]);
    }

    // ── 2. Stock Movements (Ledger) ───────────────────────────────────────────
    public function stockMovements(Request $request): JsonResponse
    {
        $perPage = 50;

        $query = DB::table('inv_stock_transactions as st')
            ->join('inv_products as p', 'p.id', '=', 'st.product_id')
            ->join('inv_locations as l', 'l.id', '=', 'st.location_id')
            ->join('inv_stores as s', 's.id', '=', 'st.store_id')
            // Colour is on the ledger row itself — reference_id names the document, not the
            // line, so it could never have been joined back to reliably.
            ->leftJoin('inv_attributes as a', 'a.id', '=', 'st.attribute_id')
            // qty_in/qty_out speak the stocking UOM; entered_* speak the UOM transacted in.
            ->leftJoin('inv_unit_types as u', 'u.id', '=', 'st.unit_id')
            ->leftJoin('inv_unit_types as eu', 'eu.id', '=', 'st.entered_unit_id')
            ->select([
                'st.id',
                'st.transaction_date',
                'p.product_code',
                'p.name as product_name',
                'st.attribute_id',
                'a.attribute_name as color',
                'st.reference_type',
                'st.reference_id',
                'st.batch_no',
                'st.qty_in',
                'st.qty_out',
                'u.symbol as uom',
                'st.entered_qty',
                'eu.symbol as entered_uom',
                'st.unit_price',
                'l.location_name',
                's.store_name',
                'st.created_by',
            ]);

        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('p.name', 'like', $search)
                  ->orWhere('p.product_code', 'like', $search)
                  ->orWhere('a.attribute_name', 'like', $search)
                  ->orWhere('st.batch_no', 'like', $search);
            });
        }

        if ($request->filled('product_id')) {
            $query->where('st.product_id', $request->integer('product_id'));
        }

        if ($request->filled('attribute_id')) {
            $query->where('st.attribute_id', $request->integer('attribute_id'));
        }

        if ($request->filled('location_id')) {
            $query->where('st.location_id', $request->integer('location_id'));
        }

        if ($request->filled('store_id')) {
            $query->where('st.store_id', $request->integer('store_id'));
        }

        if ($request->filled('reference_type')) {
            $query->where('st.reference_type', $request->input('reference_type'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('st.transaction_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('st.transaction_date', '<=', $request->input('date_to'));
        }

        $paginator = $query->orderByDesc('st.transaction_date')->orderByDesc('st.id')->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    // ── 3. Low Stock / Reorder Alert ──────────────────────────────────────────
    public function lowStock(Request $request): JsonResponse
    {
        $perPage = 50;

        $query = DB::table('inv_product_location_stores as pls')
            ->join('inv_products as p', 'p.id', '=', 'pls.product_id')
            ->join('inv_locations as l', 'l.id', '=', 'pls.location_id')
            ->join('inv_stores as s', 's.id', '=', 'pls.store_id')
            ->leftJoin('inv_categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.reorder_level', '>', 0)
            ->whereRaw('pls.current_stock <= p.reorder_level')
            ->select([
                'p.product_code',
                'p.name as product_name',
                'c.category_name',
                'l.location_name',
                's.store_name',
                'pls.current_stock',
                'p.reorder_level',
                'p.reorder_qty',
                DB::raw('GREATEST(0, p.reorder_level - pls.current_stock) as deficit_qty'),
            ]);

        if ($request->filled('product_id')) {
            $query->where('pls.product_id', $request->integer('product_id'));
        }

        if ($request->filled('product_code')) {
            $query->where('p.product_code', 'like', '%' . $request->input('product_code') . '%');
        }

        if ($request->filled('location_id')) {
            $query->where('pls.location_id', $request->integer('location_id'));
        }

        if ($request->filled('store_id')) {
            $query->where('pls.store_id', $request->integer('store_id'));
        }

        if ($request->filled('category_id')) {
            $query->where('p.category_id', $request->integer('category_id'));
        }

        $paginator = $query->orderByDesc(DB::raw('p.reorder_level - pls.current_stock'))->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    // ── 4. Stock Valuation ────────────────────────────────────────────────────
    // Quantity  → inv_product_location_stores.current_stock  (O(1) per row, always fast)
    // Unit cost → weighted average from inv_stock_transactions grouped by product only
    //             (computed once as a subquery, not per page-scan)
    public function stockValuation(Request $request): JsonResponse
    {
        $perPage = 50;

        // Weighted average cost per product (subquery runs once)
        $wacSubquery = DB::raw(
            '(SELECT product_id,
                     SUM(qty_in * unit_price) / NULLIF(SUM(qty_in), 0) AS avg_unit_cost
              FROM inv_stock_transactions
              WHERE qty_in > 0
              GROUP BY product_id) AS wac'
        );

        $query = DB::table('inv_product_location_stores as pls')
            ->join('inv_products as p', 'p.id', '=', 'pls.product_id')
            ->join('inv_locations as l', 'l.id', '=', 'pls.location_id')
            ->join('inv_stores as s', 's.id', '=', 'pls.store_id')
            ->leftJoin('inv_categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin($wacSubquery, 'wac.product_id', '=', 'p.id')
            ->where('pls.current_stock', '>', 0)
            ->select([
                'p.product_code',
                'p.name as product_name',
                'c.category_name',
                'l.location_name',
                's.store_name',
                'pls.current_stock',
                DB::raw('COALESCE(wac.avg_unit_cost, 0) as avg_unit_cost'),
                DB::raw('pls.current_stock * COALESCE(wac.avg_unit_cost, 0) as total_value'),
            ]);

        if ($request->filled('product_id')) {
            $query->where('pls.product_id', $request->integer('product_id'));
        }

        if ($request->filled('product_code')) {
            $query->where('p.product_code', 'like', '%' . $request->input('product_code') . '%');
        }

        if ($request->filled('location_id')) {
            $query->where('pls.location_id', $request->integer('location_id'));
        }

        if ($request->filled('store_id')) {
            $query->where('pls.store_id', $request->integer('store_id'));
        }

        if ($request->filled('category_id')) {
            $query->where('p.category_id', $request->integer('category_id'));
        }

        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('p.name', 'like', $search)
                  ->orWhere('p.product_code', 'like', $search);
            });
        }

        $paginator = $query->orderByDesc(DB::raw('pls.current_stock * COALESCE(wac.avg_unit_cost, 0)'))->paginate($perPage);

        // Grand total — re-run the same base filters without pagination
        $totalQuery = DB::table('inv_product_location_stores as pls')
            ->join('inv_products as p', 'p.id', '=', 'pls.product_id')
            ->leftJoin($wacSubquery, 'wac.product_id', '=', 'p.id')
            ->where('pls.current_stock', '>', 0);

        if ($request->filled('product_id')) {
            $totalQuery->where('pls.product_id', $request->integer('product_id'));
        }
        if ($request->filled('product_code')) {
            $totalQuery->where('p.product_code', 'like', '%' . $request->input('product_code') . '%');
        }
        if ($request->filled('location_id')) {
            $totalQuery->where('pls.location_id', $request->integer('location_id'));
        }
        if ($request->filled('store_id')) {
            $totalQuery->where('pls.store_id', $request->integer('store_id'));
        }
        if ($request->filled('category_id')) {
            $totalQuery->where('p.category_id', $request->integer('category_id'));
        }
        if ($request->filled('search')) {
            $s = '%' . $request->input('search') . '%';
            $totalQuery->where(function ($q) use ($s) {
                $q->where('p.name', 'like', $s)->orWhere('p.product_code', 'like', $s);
            });
        }

        $totalValue = $totalQuery->sum(DB::raw('pls.current_stock * COALESCE(wac.avg_unit_cost, 0)'));

        return response()->json([
            'data'    => $paginator->items(),
            'summary' => ['total_value' => round((float) $totalValue, 2)],
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    // ── 5. Batch / Expiry Tracking ────────────────────────────────────────────
    public function batchExpiry(Request $request): JsonResponse
    {
        $perPage = 50;

        $query = DB::table('inv_batches as b')
            ->join('inv_products as p', 'p.id', '=', 'b.product_id')
            ->leftJoin('inv_supplier_masters as sm', 'sm.id', '=', 'b.supplier_id')
            ->select([
                'b.id',
                'b.batch_no',
                'p.product_code',
                'p.name as product_name',
                'sm.supplier_name',
                'b.mfg_date',
                'b.expiry_date',
                'b.received_date',
                'b.initial_qty',
                'b.current_qty',
                'b.unit_cost',
                'b.status',
                DB::raw('DATEDIFF(b.expiry_date, CURDATE()) as days_to_expiry'),
            ])
            ->whereNull('b.deleted_at');

        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('b.batch_no', 'like', $search)
                  ->orWhere('p.name', 'like', $search)
                  ->orWhere('p.product_code', 'like', $search);
            });
        }

        if ($request->filled('batch_no')) {
            $query->where('b.batch_no', 'like', '%' . $request->input('batch_no') . '%');
        }

        if ($request->filled('product_code')) {
            $query->where('p.product_code', 'like', '%' . $request->input('product_code') . '%');
        }

        if ($request->filled('product_id')) {
            $query->where('b.product_id', $request->integer('product_id'));
        }

        if ($request->filled('status')) {
            $query->where('b.status', $request->input('status'));
        }

        if ($request->filled('expiry_days')) {
            $days = $request->integer('expiry_days');
            $query->whereNotNull('b.expiry_date')
                  ->whereRaw('b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)', [$days])
                  ->whereRaw('b.expiry_date >= CURDATE()');
        }

        if ($request->filled('date_from')) {
            $query->whereDate('b.expiry_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('b.expiry_date', '<=', $request->input('date_to'));
        }

        $paginator = $query->orderBy('b.expiry_date')->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    // ── 6. Purchase Requests Summary ──────────────────────────────────────────
    public function purchaseRequests(Request $request): JsonResponse
    {
        $perPage = 50;

        $query = DB::table('inv_purchase_requests as pr')
            ->leftJoin(
                DB::raw('(SELECT pr_id, COUNT(*) as item_count, SUM(quantity * estimated_unit_price) as estimated_value FROM inv_purchase_request_items GROUP BY pr_id) as item_agg'),
                'item_agg.pr_id', '=', 'pr.id'
            )
            ->leftJoin('users as u', 'u.id', '=', 'pr.requested_by')
            ->select([
                'pr.id',
                'pr.pr_no',
                'pr.reference_no',
                'pr.request_date',
                'pr.required_date',
                'pr.status',
                'pr.purpose',
                'u.name as requested_by_name',
                'pr.approved_by',
                'pr.approved_at',
                'pr.rejection_reason',
                DB::raw('COALESCE(item_agg.item_count, 0) as item_count'),
                DB::raw('COALESCE(item_agg.estimated_value, 0) as estimated_value'),
            ])
            ->whereNull('pr.deleted_at');

        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('pr.pr_no', 'like', $search)
                  ->orWhere('pr.reference_no', 'like', $search)
                  ->orWhere('pr.purpose', 'like', $search);
            });
        }

        if ($request->filled('pr_no')) {
            $query->where('pr.pr_no', 'like', '%' . $request->input('pr_no') . '%');
        }

        if ($request->filled('requested_by')) {
            $query->where('u.name', 'like', '%' . $request->input('requested_by') . '%');
        }

        if ($request->filled('status')) {
            $query->where('pr.status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('pr.request_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('pr.request_date', '<=', $request->input('date_to'));
        }

        $paginator = $query->orderByDesc('pr.request_date')->orderByDesc('pr.id')->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    // ── 7. Purchase Orders ────────────────────────────────────────────────────
    public function purchaseOrders(Request $request): JsonResponse
    {
        $perPage = 50;

        $query = DB::table('inv_purchase_orders as po')
            ->leftJoin('inv_supplier_masters as sm', 'sm.id', '=', 'po.supplier_id')
            ->leftJoin('inv_locations as l', 'l.id', '=', 'po.location_id')
            ->leftJoin('inv_stores as s', 's.id', '=', 'po.store_id')
            ->leftJoin(
                DB::raw('(SELECT po_id, COUNT(*) as item_count FROM inv_purchase_order_items GROUP BY po_id) as item_agg'),
                'item_agg.po_id', '=', 'po.id'
            )
            ->select([
                'po.id',
                'po.po_no',
                'po.reference_no',
                'po.order_date',
                'po.expected_delivery_date',
                'po.status',
                'po.subtotal',
                'po.grand_total',
                'po.payment_terms',
                'sm.supplier_name',
                'sm.supplier_code',
                'l.location_name',
                's.store_name',
                DB::raw('COALESCE(item_agg.item_count, 0) as item_count'),
            ])
            ->whereNull('po.deleted_at');

        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('po.po_no', 'like', $search)
                  ->orWhere('po.reference_no', 'like', $search)
                  ->orWhere('sm.supplier_name', 'like', $search);
            });
        }

        if ($request->filled('status')) {
            $query->where('po.status', $request->input('status'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('po.supplier_id', $request->integer('supplier_id'));
        }

        if ($request->filled('location_id')) {
            $query->where('po.location_id', $request->integer('location_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('po.order_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('po.order_date', '<=', $request->input('date_to'));
        }

        $paginator = $query->orderByDesc('po.order_date')->orderByDesc('po.id')->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    // ── 8. Outstanding Purchase Orders ────────────────────────────────────────
    public function outstandingPOs(Request $request): JsonResponse
    {
        $perPage = 50;

        $query = DB::table('inv_purchase_order_items as poi')
            ->join('inv_purchase_orders as po', 'po.id', '=', 'poi.po_id')
            ->join('inv_products as p', 'p.id', '=', 'poi.product_id')
            ->leftJoin('inv_supplier_masters as sm', 'sm.id', '=', 'po.supplier_id')
            ->leftJoin('inv_locations as l', 'l.id', '=', 'po.location_id')
            ->leftJoin('inv_stores as s', 's.id', '=', 'po.store_id')
            ->where('poi.quantity_ordered', '>', DB::raw('poi.quantity_received'))
            ->whereIn('po.status', ['confirmed', 'partially_received'])
            ->whereNull('po.deleted_at')
            ->select([
                'po.po_no',
                'po.order_date',
                'po.expected_delivery_date',
                'po.status as po_status',
                'sm.supplier_name',
                'l.location_name',
                's.store_name',
                'p.product_code',
                'p.name as product_name',
                'poi.quantity_ordered',
                'poi.quantity_received',
                DB::raw('poi.quantity_ordered - poi.quantity_received as remaining_qty'),
                'poi.unit_price',
                DB::raw('(poi.quantity_ordered - poi.quantity_received) * poi.unit_price as remaining_value'),
                DB::raw('CASE WHEN po.expected_delivery_date < CURDATE() THEN 1 ELSE 0 END as is_overdue'),
                DB::raw('DATEDIFF(CURDATE(), po.expected_delivery_date) as overdue_days'),
            ]);

        if ($request->filled('supplier_id')) {
            $query->where('po.supplier_id', $request->integer('supplier_id'));
        }

        if ($request->filled('location_id')) {
            $query->where('po.location_id', $request->integer('location_id'));
        }

        if ($request->filled('store_id')) {
            $query->where('po.store_id', $request->integer('store_id'));
        }

        if ($request->filled('product_id')) {
            $query->where('poi.product_id', $request->integer('product_id'));
        }

        if ($request->boolean('overdue_only')) {
            $query->whereNotNull('po.expected_delivery_date')
                  ->whereRaw('po.expected_delivery_date < CURDATE()');
        }

        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('po.po_no', 'like', $search)
                  ->orWhere('sm.supplier_name', 'like', $search)
                  ->orWhere('p.name', 'like', $search);
            });
        }

        $paginator = $query->orderByDesc(DB::raw('CASE WHEN po.expected_delivery_date < CURDATE() THEN 1 ELSE 0 END'))
                           ->orderBy('po.expected_delivery_date')
                           ->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    // ── 9. GRN Report ─────────────────────────────────────────────────────────
    public function grn(Request $request): JsonResponse
    {
        $perPage = 50;

        $query = DB::table('inv_goods_received_notes as grn')
            ->leftJoin('inv_supplier_masters as sm', 'sm.id', '=', 'grn.supplier_id')
            ->leftJoin('inv_purchase_orders as po', 'po.id', '=', 'grn.po_id')
            ->leftJoin('inv_locations as l', 'l.id', '=', 'grn.location_id')
            ->leftJoin('inv_stores as s', 's.id', '=', 'grn.store_id')
            ->leftJoin(
                DB::raw('(SELECT grn_id, COUNT(*) as item_count, SUM(quantity_received) as total_qty FROM inv_goods_received_note_items GROUP BY grn_id) as item_agg'),
                'item_agg.grn_id', '=', 'grn.id'
            )
            ->select([
                'grn.id',
                'grn.grn_no',
                'grn.reference_no',
                'grn.grn_date',
                'grn.transaction_date',
                'grn.status',
                'grn.total_amount',
                'grn.payment_terms',
                'po.po_no',
                'sm.supplier_name',
                'sm.supplier_code',
                'l.location_name',
                's.store_name',
                DB::raw('COALESCE(item_agg.item_count, 0) as item_count'),
                DB::raw('COALESCE(item_agg.total_qty, 0) as total_qty'),
            ])
            ->whereNull('grn.deleted_at');

        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('grn.grn_no', 'like', $search)
                  ->orWhere('grn.reference_no', 'like', $search)
                  ->orWhere('sm.supplier_name', 'like', $search)
                  ->orWhere('po.po_no', 'like', $search);
            });
        }

        if ($request->filled('status')) {
            $query->where('grn.status', $request->input('status'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('grn.supplier_id', $request->integer('supplier_id'));
        }

        if ($request->filled('location_id')) {
            $query->where('grn.location_id', $request->integer('location_id'));
        }

        if ($request->filled('store_id')) {
            $query->where('grn.store_id', $request->integer('store_id'));
        }

        if ($request->filled('grn_no')) {
            $query->where('grn.grn_no', 'like', '%' . $request->input('grn_no') . '%');
        }

        if ($request->filled('date_from')) {
            $query->whereDate('grn.grn_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('grn.grn_date', '<=', $request->input('date_to'));
        }

        $paginator = $query->orderByDesc('grn.grn_date')->orderByDesc('grn.id')->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    // ── 10. Supplier Purchase Summary ─────────────────────────────────────────
    public function supplierSummary(Request $request): JsonResponse
    {
        $perPage = 50;

        $query = DB::table('inv_supplier_masters as sm')
            ->leftJoin(
                DB::raw('(SELECT supplier_id, COUNT(*) as po_count, SUM(grand_total) as po_value, MAX(order_date) as last_order_date FROM inv_purchase_orders WHERE deleted_at IS NULL GROUP BY supplier_id) as po_agg'),
                'po_agg.supplier_id', '=', 'sm.id'
            )
            ->leftJoin(
                DB::raw('(SELECT supplier_id, COUNT(*) as grn_count, SUM(total_amount) as grn_value FROM inv_goods_received_notes WHERE deleted_at IS NULL AND status = \'confirmed\' GROUP BY supplier_id) as grn_agg'),
                'grn_agg.supplier_id', '=', 'sm.id'
            )
            ->whereNull('sm.deleted_at')
            ->select([
                'sm.id',
                'sm.supplier_code',
                'sm.supplier_name',
                'sm.supplier_type',
                'sm.email',
                'sm.mobile',
                DB::raw('COALESCE(po_agg.po_count, 0) as po_count'),
                DB::raw('COALESCE(po_agg.po_value, 0) as po_value'),
                DB::raw('COALESCE(grn_agg.grn_count, 0) as grn_count'),
                DB::raw('COALESCE(grn_agg.grn_value, 0) as grn_value'),
                'po_agg.last_order_date',
            ]);

        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('sm.supplier_name', 'like', $search)
                  ->orWhere('sm.supplier_code', 'like', $search);
            });
        }

        if ($request->filled('date_from') || $request->filled('date_to')) {
            $dateFrom = $request->input('date_from');
            $dateTo   = $request->input('date_to');

            $poSubQuery = DB::table('inv_purchase_orders')
                ->whereNull('deleted_at')
                ->selectRaw('supplier_id, COUNT(*) as po_count, SUM(grand_total) as po_value, MAX(order_date) as last_order_date')
                ->groupBy('supplier_id');

            if ($dateFrom) {
                $poSubQuery->whereDate('order_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $poSubQuery->whereDate('order_date', '<=', $dateTo);
            }

            $grnSubQuery = DB::table('inv_goods_received_notes')
                ->whereNull('deleted_at')
                ->where('status', 'confirmed')
                ->selectRaw('supplier_id, COUNT(*) as grn_count, SUM(total_amount) as grn_value')
                ->groupBy('supplier_id');

            if ($dateFrom) {
                $grnSubQuery->whereDate('grn_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $grnSubQuery->whereDate('grn_date', '<=', $dateTo);
            }

            $query = DB::table('inv_supplier_masters as sm')
                ->leftJoinSub($poSubQuery, 'po_agg', fn ($j) => $j->on('po_agg.supplier_id', '=', 'sm.id'))
                ->leftJoinSub($grnSubQuery, 'grn_agg', fn ($j) => $j->on('grn_agg.supplier_id', '=', 'sm.id'))
                ->whereNull('sm.deleted_at')
                ->select([
                    'sm.id',
                    'sm.supplier_code',
                    'sm.supplier_name',
                    'sm.supplier_type',
                    'sm.email',
                    'sm.mobile',
                    DB::raw('COALESCE(po_agg.po_count, 0) as po_count'),
                    DB::raw('COALESCE(po_agg.po_value, 0) as po_value'),
                    DB::raw('COALESCE(grn_agg.grn_count, 0) as grn_count'),
                    DB::raw('COALESCE(grn_agg.grn_value, 0) as grn_value'),
                    'po_agg.last_order_date',
                ]);

            if ($request->filled('search')) {
                $search = '%' . $request->input('search') . '%';
                $query->where(function ($q) use ($search) {
                    $q->where('sm.supplier_name', 'like', $search)
                      ->orWhere('sm.supplier_code', 'like', $search);
                });
            }
        }

        $paginator = $query->orderByDesc(DB::raw('COALESCE(po_agg.po_value, 0)'))->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    // ── 11. Landed Costs ──────────────────────────────────────────────────────
    public function landedCosts(Request $request): JsonResponse
    {
        $perPage = 50;

        $query = DB::table('inv_costings as c')
            ->leftJoin('inv_supplier_masters as sm', 'sm.id', '=', 'c.supplier_id')
            ->leftJoin(
                DB::raw('(SELECT costing_id, SUM(amount) as total_expenses FROM inv_costing_expenses GROUP BY costing_id) as exp_agg'),
                'exp_agg.costing_id', '=', 'c.id'
            )
            ->leftJoin(
                DB::raw('(SELECT costing_id, COUNT(*) as grn_count FROM inv_costing_grns GROUP BY costing_id) as grn_agg'),
                'grn_agg.costing_id', '=', 'c.id'
            )
            ->whereNull('c.deleted_at')
            ->select([
                'c.id',
                'c.document_no',
                'c.reference_no',
                'c.costing_type',
                'c.status',
                'c.transaction_date',
                'c.material_cost',
                'c.raw_material_cost',
                'c.total_landed_cost',
                'c.vat_amount',
                'c.total_price_with_vat',
                'sm.supplier_name',
                'sm.supplier_code',
                DB::raw('COALESCE(exp_agg.total_expenses, 0) as total_additional_expenses'),
                DB::raw('COALESCE(grn_agg.grn_count, 0) as grn_count'),
            ]);

        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('c.document_no', 'like', $search)
                  ->orWhere('c.reference_no', 'like', $search)
                  ->orWhere('sm.supplier_name', 'like', $search);
            });
        }

        if ($request->filled('status')) {
            $query->where('c.status', $request->input('status'));
        }

        if ($request->filled('costing_type')) {
            $query->where('c.costing_type', $request->input('costing_type'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('c.supplier_id', $request->integer('supplier_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('c.transaction_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('c.transaction_date', '<=', $request->input('date_to'));
        }

        $paginator = $query->orderByDesc('c.transaction_date')->orderByDesc('c.id')->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    // ── 12. Bin Card (per-product stock ledger with running balance) ─────────
    // Full ledger for one product (no pagination — the running balance is
    // sequential and the PDF/CSV exports need the complete set anyway).
    public function binCard(Request $request, BinCardService $service): JsonResponse
    {
        $request->validate([
            'product_id'   => ['required', 'integer', 'exists:inv_products,id'],
            'attribute_id' => ['nullable', 'integer', 'exists:inv_attributes,id'],
            'location_id'  => ['nullable', 'integer', 'exists:inv_locations,id'],
            'store_id'     => ['nullable', 'integer', 'exists:inv_stores,id'],
            'date_from'    => ['nullable', 'date'],
            'date_to'      => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        return response()->json($service->build(
            $request->only(['product_id', 'attribute_id', 'location_id', 'store_id', 'date_from', 'date_to'])
        ));
    }

    // ── 13. Stock Movement Summary (location wise, per product) ──────────────
    // One row per product: opening / purchase (GRN) / sales / closing, qty + value.
    // No pagination — the PDF/CSV exports need the complete set anyway.
    public function stockMovementSummary(Request $request, StockMovementSummaryService $service): JsonResponse
    {
        $request->validate([
            'location_id' => ['nullable', 'integer', 'exists:inv_locations,id'],
            'store_id'    => ['nullable', 'integer', 'exists:inv_stores,id'],
            'category_id' => ['nullable', 'integer', 'exists:inv_categories,id'],
            'date_from'   => ['nullable', 'date'],
            'date_to'     => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        return response()->json($service->build(
            $request->only(['location_id', 'store_id', 'category_id', 'date_from', 'date_to'])
        ));
    }

    // ── 14. Sales By Item Summary (category wise, product + colour) ──────────
    // One row per product+colour: total sold qty + amount, from Issued/Paid invoices.
    // No pagination — the PDF/CSV exports need the complete set anyway.
    public function salesByItemSummary(Request $request, SalesByItemSummaryService $service): JsonResponse
    {
        $request->validate([
            'location_id' => ['nullable', 'integer', 'exists:inv_locations,id'],
            'category_id' => ['nullable', 'integer', 'exists:inv_categories,id'],
            'date_from'   => ['nullable', 'date'],
            'date_to'     => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        return response()->json($service->build(
            $request->only(['location_id', 'category_id', 'date_from', 'date_to'])
        ));
    }

    // ── 15. Sales By Item Detail Summary (category > product > transaction) ──
    // One row per invoice line: date, invoice no, customer, qty, price, amount.
    // No pagination — the PDF/CSV exports need the complete set anyway; both
    // date bounds are required to keep the transaction-level result bounded.
    public function salesByItemDetailSummary(Request $request, SalesByItemDetailSummaryService $service): JsonResponse
    {
        $request->validate([
            'date_from'   => ['required', 'date'],
            'date_to'     => ['required', 'date', 'after_or_equal:date_from'],
            'category_id' => ['nullable', 'integer', 'exists:inv_categories,id'],
            'product_id'  => ['nullable', 'integer', 'exists:inv_products,id'],
        ]);

        return response()->json($service->build(
            $request->only(['date_from', 'date_to', 'category_id', 'product_id'])
        ));
    }

    // ── 16. Sales By Customer Details (customer > transaction) ───────────────
    // One row per invoice line: date, invoice no, item details, qty, price, amount.
    // No pagination — the PDF/CSV exports need the complete set anyway; both
    // date bounds are required to keep the transaction-level result bounded.
    public function salesByCustomerDetails(Request $request, SalesByCustomerDetailsService $service): JsonResponse
    {
        $request->validate([
            'date_from'   => ['required', 'date'],
            'date_to'     => ['required', 'date', 'after_or_equal:date_from'],
            'location_id' => ['nullable', 'integer', 'exists:inv_locations,id'],
            'customer_id' => ['nullable', 'integer', 'exists:inv_customer_masters,id'],
            'product_id'  => ['nullable', 'integer', 'exists:inv_products,id'],
        ]);

        return response()->json($service->build(
            $request->only(['date_from', 'date_to', 'location_id', 'customer_id', 'product_id'])
        ));
    }

    // ── 17. Supplier Wise GRN Details (supplier > GRN > item) ────────────────
    // One row per GRN item: qty, unit price, discount, tax, amount, grouped under
    // each GRN's header (GRN No / Date / PO No / Total Qty) and each supplier.
    // No pagination — the PDF/CSV exports need the complete set anyway; both date
    // bounds are required to keep the transaction-level result bounded.
    public function supplierWiseGrnDetails(Request $request, SupplierWiseGrnDetailsService $service): JsonResponse
    {
        $request->validate([
            'date_from'   => ['required', 'date'],
            'date_to'     => ['required', 'date', 'after_or_equal:date_from'],
            'supplier_id' => ['nullable', 'integer', 'exists:inv_supplier_masters,id'],
            'product_id'  => ['nullable', 'integer', 'exists:inv_products,id'],
        ]);

        return response()->json($service->build(
            $request->only(['date_from', 'date_to', 'supplier_id', 'product_id'])
        ));
    }

    // ── 18. Item Search Report (product+colour+GRN, current price & stock) ───
    // One row per GRN receipt of a product+colour: its Selling Price (from that
    // GRN's Costing, or the product's first Sales Channel price) and its Stock
    // (remaining batch balance, or the product+colour's pooled balance for
    // non-batch items). Paginated — not date-bound, this is a lookup, not a ledger.
    public function itemSearch(Request $request, ItemSearchReportService $service): JsonResponse
    {
        $request->validate([
            'product_code' => ['nullable', 'string', 'max:100'],
            'product_id'   => ['nullable', 'integer', 'exists:inv_products,id'],
            'category_id'  => ['nullable', 'integer', 'exists:inv_categories,id'],
            'supplier_id'  => ['nullable', 'integer', 'exists:inv_supplier_masters,id'],
            'grn_id'       => ['nullable', 'integer', 'exists:inv_goods_received_notes,id'],
        ]);

        return response()->json($service->build(
            $request->only(['product_code', 'product_id', 'category_id', 'supplier_id', 'grn_id']),
            (int) $request->integer('page', 1)
        ));
    }

    // ── 19. Outstanding Summary (customer > unpaid/partly-paid invoices) ─────
    // Live snapshot, not date-bound — an invoice's outstanding balance is "as of
    // now", not a historical figure. No pagination — the PDF/CSV exports need the
    // complete set anyway, and the set of currently-outstanding invoices is
    // naturally bounded (guarded regardless — see MAX_ROWS in the service).
    public function outstandingSummary(Request $request, OutstandingSummaryReportService $service): JsonResponse
    {
        $request->validate([
            'customer_id'  => ['nullable', 'integer', 'exists:inv_customer_masters,id'],
            'overdue_only' => ['nullable', 'boolean'],
        ]);

        return response()->json($service->build([
            'customer_id'  => $request->integer('customer_id') ?: null,
            'overdue_only' => $request->boolean('overdue_only'),
        ]));
    }

    // ── 20. Sales Summary (date-wise Cash/Credit/Cheque/Bank Deposit/Cards) ──
    // One row per calendar date: money actually collected that day by payment method
    // (from confirmed Customer Receipt settlements) plus new Credit sales issued that
    // day. No pagination — the PDF/CSV exports need the complete set anyway.
    public function salesSummary(Request $request, SalesSummaryReportService $service): JsonResponse
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        return response()->json($service->build(
            $request->only(['date_from', 'date_to'])
        ));
    }
}
