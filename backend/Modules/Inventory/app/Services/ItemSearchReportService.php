<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Models\StockReferenceType;

class ItemSearchReportService
{
    private const PER_PAGE = 50;

    /**
     * Build the Item Search Report: one row per product+colour+GRN+batch, showing
     * where it stands right now — its Selling Price and remaining Stock balance.
     *
     * Rows are sourced from the stock ledger (inv_stock_transactions, reference_type
     * = grn), not the GRN items table directly — a GRN line that got split across
     * several batches at confirm time posts one ledger row per batch, and that split
     * is exactly what "batch is just a code" needs: each batch keeps its own Stock,
     * while Selling Price stays tied to the GRN it was costed under. GRN receipts
     * always post to the ledger at confirm time, so this is implicitly Confirmed-only.
     *
     * Grouped by (grn, product, colour, batch) rather than shown one row per raw
     * ledger row — a GRN can carry two separate line items for the same product+
     * colour (e.g. entered as two rows by mistake, or received in two checks), each
     * posting its own ledger row with identical GRN/price/etc. Those are merged into
     * one row here rather than shown as look-alike duplicates. Batch is still part of
     * the grouping key, so two genuinely distinct batches never merge into one.
     *
     * Selling Price: the Costing done for that specific GRN (inv_costing_items,
     * matched by grn_id + product_id + attribute_id, base-UOM price) when one exists;
     * otherwise the product's first configured Sales Channel price. A GRN can end up
     * costed more than once (re-costed), so the match picks the highest costing_items
     * id per (grn_id, product_id, attribute_id) rather than joining directly, which
     * would fan out a row per costing.
     *
     * Stock — three tiers, most exact first:
     *
     *  1. Roll/piece-tracked (inv_grn_item_pieces rows exist for this GRN line): the
     *     exact SUM(weight) of its pieces still in_stock or allocated. A roll never
     *     changes which GRN it belongs to, even after being cut — the remnant copies
     *     grn_id/grn_item_id from its parent (RollService::cut()) — so this is ground
     *     truth, not an estimate: selling a specific roll off GRN #2 reduces GRN #2's
     *     figure, never GRN #1's, regardless of receipt order or price.
     *  2. Batch-linked (st.batch_id set): the batch's own current_qty.
     *  3. Neither (plain non-batch, non-piece product): no stored link from a sale
     *     back to the GRN it depleted, so this falls back to a FIFO estimate — each
     *     GRN receipt is a "lot", oldest first, and a lot's remainder is
     *
     *       GREATEST(0, LEAST(lot qty_in, cumulative qty_in through this lot - net consumption))
     *
     *     where "cumulative qty_in" is a running total over that product+colour+
     *     location's GRN lots ordered by date (a window function), and "net
     *     consumption" is total qty_out minus non-GRN qty_in (returns, adjustments,
     *     opening stock all top the queue back up oldest-first) for that product+
     *     colour+location, across every movement type. Scoped per location. This tier
     *     is a genuine assumption (oldest-first) precisely because tiers 1 and 2 don't
     *     apply — there is nothing in the data that says which GRN a plain-qty sale
     *     actually came from.
     *
     * All three are a read-time replay of existing tables — no schema changes. This
     * report still has no grand-total Stock footer: each GRN's remainder is
     * independently meaningful, but nothing sums them into a single "total" figure
     * worth showing.
     *
     * @param array{product_code?:string|null, product_id?:int|null, category_id?:int|null, supplier_id?:int|null, grn_id?:int|null} $filters
     * @return array<string, mixed>
     */
    public function build(array $filters, int $page): array
    {
        $productCode = trim((string) ($filters['product_code'] ?? ''));
        $productId   = !empty($filters['product_id']) ? (int) $filters['product_id'] : null;
        $categoryId  = !empty($filters['category_id']) ? (int) $filters['category_id'] : null;
        $supplierId  = !empty($filters['supplier_id']) ? (int) $filters['supplier_id'] : null;
        $grnId       = !empty($filters['grn_id']) ? (int) $filters['grn_id'] : null;

        // One row per non-batch GRN *lot* — first collapse same-GRN duplicate ledger
        // rows (two line items for one product+colour) into a single lot, then run a
        // FIFO running total of qty_in over older lots of the same product+colour+
        // location (oldest first). Windowing over the collapsed lots, not raw ledger
        // rows, so a duplicate line item can't inflate its own position in the queue.
        $lotBase = DB::table('inv_stock_transactions')
            ->selectRaw('reference_id as grn_id, product_id, attribute_id, location_id')
            ->selectRaw('MIN(transaction_date) as lot_date, MIN(id) as lot_id, SUM(qty_in) as lot_qty_in')
            ->where('reference_type', StockReferenceType::CODE_GRN)
            ->whereNull('batch_id')
            ->groupBy('reference_id', 'product_id', 'attribute_id', 'location_id');

        $lotCumSub = DB::query()->fromSub($lotBase, 'lb')
            ->select('grn_id', 'product_id', 'attribute_id', 'location_id', 'lot_qty_in')
            ->selectRaw(
                'SUM(lot_qty_in) OVER (
                    PARTITION BY product_id, attribute_id, location_id
                    ORDER BY lot_date, lot_id
                ) as cum_in'
            );

        // Net consumption to date per product+colour+location, across every movement
        // type — a sale/adjustment/transfer depletes the FIFO queue, a return/opening
        // balance/adjustment-in tops it back up (oldest lot first).
        $netConsumptionSub = DB::table('inv_stock_transactions')
            ->selectRaw(
                'product_id, attribute_id, location_id,
                 SUM(qty_out) - SUM(CASE WHEN reference_type <> ? THEN qty_in ELSE 0 END) as net_consumption',
                [StockReferenceType::CODE_GRN]
            )
            ->groupBy('product_id', 'attribute_id', 'location_id');

        // Piece/roll remaining weight per (grn_id, product_id, attribute_id) — grouped
        // over EVERY piece regardless of status, so the group still exists (summing to
        // 0) once a GRN's rolls are all delivered, distinguishing "piece-tracked, none
        // left" from "never piece-tracked" (no group at all, NULL — falls through to
        // tier 2/3 below).
        $pieceStockSub = DB::table('inv_grn_item_pieces as gip')
            ->join('inv_goods_received_note_items as gi', 'gi.id', '=', 'gip.grn_item_id')
            ->groupBy('gip.grn_id', 'gip.product_id', 'gi.attribute_id')
            ->selectRaw(
                'gip.grn_id, gip.product_id, gi.attribute_id,
                 SUM(CASE WHEN gip.status IN (?, ?) THEN gip.weight ELSE 0 END) as piece_stock',
                [GrnItemPiece::STATUS_IN_STOCK, GrnItemPiece::STATUS_ALLOCATED]
            );

        // Latest costing_items row per (grn_id, product_id, attribute_id) — a GRN line
        // can be re-costed, so pick the newest rather than joining directly and
        // risking a duplicate row per costing.
        $latestCostingIds = DB::table('inv_costing_items')
            ->selectRaw('MAX(id) as id')
            ->groupBy('grn_id', 'product_id', 'attribute_id');

        $latestCosting = DB::table('inv_costing_items as ci')
            ->joinSub($latestCostingIds, 'lci', 'lci.id', '=', 'ci.id')
            ->select('ci.grn_id', 'ci.product_id', 'ci.attribute_id', 'ci.selling_price_base');

        $query = DB::table('inv_stock_transactions as st')
            ->join('inv_goods_received_notes as g', 'g.id', '=', 'st.reference_id')
            ->join('inv_products as p', 'p.id', '=', 'st.product_id')
            ->leftJoin('inv_categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('inv_supplier_masters as sm', 'sm.id', '=', 'g.supplier_id')
            ->leftJoin('inv_attributes as a', 'a.id', '=', 'st.attribute_id')
            ->leftJoin('inv_unit_types as u', 'u.id', '=', 'st.unit_id')
            ->leftJoin('inv_batches as b', 'b.id', '=', 'st.batch_id')
            ->leftJoinSub($latestCosting, 'ci', function ($join) {
                $join->on('ci.grn_id', '=', 'g.id')
                    ->on('ci.product_id', '=', 'st.product_id')
                    ->whereRaw('ci.attribute_id <=> st.attribute_id');
            })
            ->leftJoinSub($lotCumSub, 'lot', function ($join) {
                $join->on('lot.grn_id', '=', 'g.id')
                    ->on('lot.product_id', '=', 'st.product_id')
                    ->on('lot.location_id', '=', 'st.location_id')
                    ->whereRaw('lot.attribute_id <=> st.attribute_id');
            })
            ->leftJoinSub($netConsumptionSub, 'nc', function ($join) {
                $join->on('nc.product_id', '=', 'st.product_id')
                    ->on('nc.location_id', '=', 'st.location_id')
                    ->whereRaw('nc.attribute_id <=> st.attribute_id');
            })
            ->leftJoinSub($pieceStockSub, 'ps', function ($join) {
                $join->on('ps.grn_id', '=', 'g.id')
                    ->on('ps.product_id', '=', 'st.product_id')
                    ->whereRaw('ps.attribute_id <=> st.attribute_id');
            })
            ->where('st.reference_type', StockReferenceType::CODE_GRN)
            ->when($productCode !== '', fn ($q) => $q->where('p.product_code', 'like', "%{$productCode}%"))
            ->when($productId, fn ($q) => $q->where('p.id', $productId))
            ->when($categoryId, fn ($q) => $q->where('p.category_id', $categoryId))
            ->when($supplierId, fn ($q) => $q->where('g.supplier_id', $supplierId))
            ->when($grnId, fn ($q) => $q->where('g.id', $grnId))
            // Grouped by (GRN, product, colour, batch, location) so two GRN line items
            // for the same product+colour collapse into one row instead of showing as
            // look-alike duplicates. Every non-grouped select column is wrapped in an
            // aggregate — required under MySQL's ONLY_FULL_GROUP_BY, and correct here
            // since each is single-valued per group anyway (joined on the same keys).
            ->groupBy('g.id', 'st.product_id', 'st.attribute_id', 'st.batch_id', 'st.location_id')
            ->select([
                DB::raw('MIN(st.id) as row_id'),
                DB::raw('MAX(c.category_name) as category_name'),
                DB::raw('MAX(p.product_code) as product_code'),
                DB::raw('MAX(p.name) as product_name'),
                DB::raw('MAX(a.attribute_name) as attribute_name'),
                DB::raw('MAX(sm.supplier_name) as supplier_name'),
                DB::raw('MAX(g.grn_no) as grn_no'),
                DB::raw('MAX(g.grn_date) as grn_date'),
                DB::raw('MAX(st.batch_no) as batch_no'),
                DB::raw('MAX(COALESCE(u.symbol, u.name)) as uom'),
                DB::raw('COALESCE(
                    MAX(ci.selling_price_base),
                    (SELECT psc.selling_price FROM inv_product_sales_channels psc WHERE psc.product_id = st.product_id ORDER BY psc.id ASC LIMIT 1),
                    0
                ) as selling_price'),
                DB::raw('CASE
                    WHEN MAX(ps.grn_id) IS NOT NULL THEN COALESCE(MAX(ps.piece_stock), 0)
                    WHEN st.batch_id IS NOT NULL THEN MIN(b.current_qty)
                    ELSE GREATEST(0, LEAST(SUM(st.qty_in), COALESCE(MAX(lot.cum_in), SUM(st.qty_in)) - COALESCE(MAX(nc.net_consumption), 0)))
                END as stock_qty'),
            ])
            ->orderBy('category_name')
            ->orderBy('product_name')
            ->orderBy('attribute_name')
            ->orderByDesc('grn_date');

        $paginator = $query->paginate(self::PER_PAGE, ['*'], 'page', $page);

        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ];
    }
}
