<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use App\Contracts\StockValuationSource;
use App\Services\SettingsService;
use App\Support\Money\Amount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockReferenceType;

/**
 * Inventory's answer to "what was the stock worth at the end of this day".
 *
 *   value(date) = Σ per product  quantity on hand at date × average cost at date
 *
 * ── Dated by the DOCUMENT, not by the stock row ──────────────────────────────
 * inv_stock_transactions.transaction_date is the moment of confirmation. The
 * ledger dates the same GRN by its grn_date, and on live data one GRN dated
 * 30 Jul was confirmed on 20 Aug. Valuing by the row would put its stock in
 * August and its purchase in July, so each movement takes its source
 * document's date: grn_date, the DO's document_date, a return's return_date.
 * A row with no dated document (stock adjustment, opening stock) keeps its own
 * transaction date.
 *
 * ── Cost = weighted average of what was PAID ─────────────────────────────────
 * Only GRN and opening-stock rows feed the average: they carry a cost. A
 * customer-return row carries the selling price (about 1.67x cost on live
 * data), a delivery row too, and an adjustment the last GRN price; averaging
 * those in would overstate stock. The average is per product over every
 * location, so a location that only ever received returns still has a cost,
 * and it includes only receipts dated on or before the valuation date.
 * Landed cost (freight, duty) is not included: it posts to its own accounts.
 *
 * ── Two aggregate passes, whatever the number of dates ──────────────────────
 * One pass for quantities (inside the company / location asked for), one for
 * costs (all locations), each a single GROUP BY product with one conditional
 * SUM per date. The rows returned are one per product, not per movement.
 *
 * SQL is kept portable (CASE, COALESCE, DATE) because the tests run on SQLite.
 */
final class InventoryStockValuation implements StockValuationSource
{
    /** Movements whose unit_price is a purchase cost. */
    private const COST_SOURCES = [
        StockReferenceType::CODE_GRN,
        StockReferenceType::CODE_OPENING_STOCK,
    ];

    public function __construct(private readonly SettingsService $settings) {}

    public function isAvailable(): bool
    {
        return $this->settings->isEnabled('module.inventory');
    }

    public function valuesAsAt(
        array $dates,
        ?int $companyId = null,
        ?int $locationId = null,
        ?array $allowedCompanyIds = null,
    ): array {
        $dates = array_values(array_unique(array_filter($dates, fn ($d): bool => is_string($d) && $d !== '')));

        if ($dates === [] || ! $this->isAvailable()) {
            return [];
        }

        $quantities = $this->quantities($dates, $companyId, $locationId, $allowedCompanyIds);
        $costs      = $this->costs($dates);

        $values = array_fill_keys($dates, 0.0);

        foreach ($quantities as $productId => $byDate) {
            foreach ($byDate as $i => $qty) {
                $cost = $costs[$productId][$i] ?? null;

                if ($cost !== null && abs($qty) > 1e-9) {
                    $values[$dates[$i]] += $qty * $cost;
                }
            }
        }

        return array_map(fn (float $v): float => Amount::round($v), $values);
    }

    public function basis(): string
    {
        return 'Inventory stock on hand by document date, at weighted average GRN cost (landed cost excluded).';
    }

    /**
     * Quantity on hand per product at the end of each date, inside the scope.
     *
     * @param  list<string> $dates
     * @return array<int, array<int, float>> product_id => [date index => qty]
     */
    private function quantities(array $dates, ?int $companyId, ?int $locationId, ?array $allowedCompanyIds): array
    {
        $query = DB::query()->fromSub($this->movements($companyId, $locationId, $allowedCompanyIds), 'm')
            ->groupBy('m.product_id')
            ->select('m.product_id');

        foreach ($dates as $i => $date) {
            $query->selectRaw("SUM(CASE WHEN m.doc_date <= ? THEN m.qty_in - m.qty_out ELSE 0 END) AS q{$i}", [$date]);
        }

        $result = [];

        foreach ($query->get() as $row) {
            foreach (array_keys($dates) as $i) {
                $result[(int) $row->product_id][$i] = (float) $row->{"q{$i}"};
            }
        }

        return $result;
    }

    /**
     * Weighted average cost per product at the end of each date, all
     * locations. Null where nothing had been received with a cost yet.
     *
     * @param  list<string> $dates
     * @return array<int, array<int, float|null>> product_id => [date index => cost]
     */
    private function costs(array $dates): array
    {
        $query = DB::query()->fromSub($this->movements(null, null, null), 'm')
            ->whereIn('m.reference_type', self::COST_SOURCES)
            ->where('m.qty_in', '>', 0)
            ->groupBy('m.product_id')
            ->select('m.product_id');

        foreach ($dates as $i => $date) {
            $query->selectRaw("SUM(CASE WHEN m.doc_date <= ? THEN m.qty_in * COALESCE(m.unit_price, 0) ELSE 0 END) AS v{$i}", [$date]);
            $query->selectRaw("SUM(CASE WHEN m.doc_date <= ? THEN m.qty_in ELSE 0 END) AS n{$i}", [$date]);
        }

        $result = [];

        foreach ($query->get() as $row) {
            foreach (array_keys($dates) as $i) {
                $qty = (float) $row->{"n{$i}"};

                $result[(int) $row->product_id][$i] = $qty > 1e-9 ? (float) $row->{"v{$i}"} / $qty : null;
            }
        }

        return $result;
    }

    /**
     * Every stock movement with the date of the document behind it.
     *
     * Each LEFT JOIN is conditioned on reference_type, so at most one of them
     * matches a row and COALESCE picks that document's date. DATE() keeps the
     * comparison a plain 'Y-m-d' string on MySQL and SQLite alike.
     */
    private function movements(?int $companyId, ?int $locationId, ?array $allowedCompanyIds): Builder
    {
        $query = DB::table('inv_stock_transactions as t')
            ->leftJoin('inv_goods_received_notes as g', fn ($j) => $j
                ->on('g.id', '=', 't.reference_id')
                ->where('t.reference_type', StockReferenceType::CODE_GRN))
            ->leftJoin('inv_delivery_orders as d', fn ($j) => $j
                ->on('d.id', '=', 't.reference_id')
                ->where('t.reference_type', StockReferenceType::CODE_SALES_DELIVERY))
            ->leftJoin('inv_customer_returns as cr', fn ($j) => $j
                ->on('cr.id', '=', 't.reference_id')
                ->where('t.reference_type', StockReferenceType::CODE_CUSTOMER_RETURN))
            ->leftJoin('inv_supplier_returns as sr', fn ($j) => $j
                ->on('sr.id', '=', 't.reference_id')
                ->where('t.reference_type', StockReferenceType::CODE_SUPPLIER_RETURN))
            ->select([
                't.product_id',
                't.reference_type',
                't.qty_in',
                't.qty_out',
                't.unit_price',
                DB::raw('DATE(COALESCE(g.grn_date, d.document_date, cr.return_date, sr.return_date, t.transaction_date)) AS doc_date'),
            ]);

        if ($locationId !== null) {
            $query->where('t.location_id', $locationId);
        }

        // Company and company access go through the location, which is what
        // belongs to a company. A movement with no location belongs to none,
        // so it is left out once any company restriction applies.
        if ($companyId !== null || $allowedCompanyIds !== null) {
            $query->join('inv_locations as l', 'l.id', '=', 't.location_id');

            if ($companyId !== null) {
                $query->where('l.company_id', $companyId);
            }

            if ($allowedCompanyIds !== null) {
                $query->whereIn('l.company_id', $allowedCompanyIds);
            }
        }

        return $query;
    }
}
