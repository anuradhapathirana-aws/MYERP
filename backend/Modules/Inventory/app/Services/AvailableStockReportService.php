<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\CostingStatus;
use Modules\Inventory\Models\Location;
use Modules\Inventory\Support\Quantity;

/**
 * Available Stock Report — what is on the shelf right now, one row per product+colour.
 *
 * Source is the stock ledger (inv_stock_transactions), NOT the balance cache
 * (inv_product_location_stores): that cache is keyed product × store × location and has
 * no colour column at all, so it cannot answer "how much Red is left". The ledger has
 * carried attribute_id since the colour migration, and every writer (GRN confirm, DO
 * confirm, reconciliation approval) posts both sides — so SUM(qty_in - qty_out) per
 * product+colour is the same balance the cache holds, just sliced one level finer. Summed
 * across colours the two reconcile.
 *
 * Rows with a non-positive balance are dropped: this report answers "what can I sell
 * today", so a depleted or negative (allow_minus) line is noise, not an answer. The
 * comparison uses Quantity::EPSILON rather than a bare > 0 — a balance of 0.0000001 is
 * float dust left by unit conversion, not stock.
 *
 * Ledger rows written before the colour migration, and any the backfill could not
 * resolve unambiguously, carry a NULL attribute_id. They group into their own
 * "no colour" row rather than being silently folded into a real colour.
 *
 * Selling Price is OPTIONAL — the admin ticks "Include Selling Price" and only then is it
 * resolved and returned. Untouched, the report stays a pure quantity list and does no
 * pricing work at all. When asked for, a product+colour's price is:
 *
 *   1. the newest CONFIRMED costing line for that exact product+colour
 *      (inv_costing_items.selling_price_base — already per the stocking UOM, computed
 *      with the GRN line's frozen conversion factor). A GRN can live in only one
 *      confirmed costing, but a product is costed once per shipment, so "newest" means
 *      the price the most recent shipment sells at — the same "old stock at old price,
 *      new stock at new price" rule ProductPricingService::resolvePieceSellingPrice
 *      applies to a roll, evaluated at product+colour level.
 *   2. otherwise the product's price-list row (inv_product_sales_channels, first row),
 *      re-expressed per the stocking UOM — a list price of 400 filed against "m" is not
 *      400 per yard.
 *
 * Null when neither exists: no price is honest, a wrong one is not. Both lookups are
 * batched over the whole result set (two queries, plus one conversion-factor lookup per
 * distinct unit pair), never per row.
 */
class AvailableStockReportService
{
    /**
     * Hard ceiling on product+colour rows in one report — protects the JSON payload and
     * DomPDF rendering. Only reachable on a huge, unfiltered catalogue.
     */
    private const MAX_ROWS = 5000;

    /** Memoised unit-conversion factors, keyed "from|to" — a report touches only a handful of unit pairs. */
    private array $factorCache = [];

    public function __construct(private readonly UnitConversionService $units)
    {
    }

    /**
     * Build the report dataset (header block, product+colour rows, summary). Shared by the
     * JSON, PDF and CSV endpoints so the aggregation exists exactly once.
     *
     * @param array{product_id?:int|null, category_id?:int|null, attribute_id?:int|null, location_id?:int|null, include_selling_price?:bool} $filters
     * @return array<string, mixed>
     */
    public function build(array $filters): array
    {
        $productId   = !empty($filters['product_id']) ? (int) $filters['product_id'] : null;
        $categoryId  = !empty($filters['category_id']) ? (int) $filters['category_id'] : null;
        $attributeId = !empty($filters['attribute_id']) ? (int) $filters['attribute_id'] : null;
        $locationId  = !empty($filters['location_id']) ? (int) $filters['location_id'] : null;
        $withPrice   = (bool) ($filters['include_selling_price'] ?? false);

        $aggregates = DB::table('inv_stock_transactions as st')
            ->join('inv_products as p', 'p.id', '=', 'st.product_id')
            ->leftJoin('inv_categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('inv_attributes as a', 'a.id', '=', 'st.attribute_id')
            // Balances are denominated in the product's stocking UOM, so the label comes
            // from the product, never from the individual movement's entered unit.
            ->leftJoin('inv_unit_types as u', 'u.id', '=', 'p.base_unit_type_id')
            ->when($productId, fn ($q) => $q->where('st.product_id', $productId))
            ->when($categoryId, fn ($q) => $q->where('p.category_id', $categoryId))
            ->when($attributeId, fn ($q) => $q->where('st.attribute_id', $attributeId))
            // No location filter = balance across every location, which is what
            // "available" means to someone deciding whether an order can be met at all.
            ->when($locationId, fn ($q) => $q->where('st.location_id', $locationId))
            ->selectRaw(
                'st.product_id,
                 st.attribute_id,
                 p.product_code,
                 p.name as product_name,
                 p.base_unit_type_id,
                 c.category_name,
                 a.attribute_name,
                 COALESCE(u.symbol, u.name) as unit,
                 SUM(st.qty_in - st.qty_out) as available_qty'
            )
            ->groupBy(
                'st.product_id', 'st.attribute_id',
                'p.product_code', 'p.name', 'p.base_unit_type_id',
                'c.category_name', 'a.attribute_name', 'u.symbol', 'u.name'
            )
            // The tolerance is inlined as a decimal literal, never bound: a bound float
            // arrives as TEXT, and SQLite sorts every text value above every number, so
            // "balance > ?" silently matched nothing. It is a class constant, not input.
            ->havingRaw('SUM(st.qty_in - st.qty_out) > ' . sprintf('%.8F', Quantity::EPSILON))
            ->orderBy('p.name')
            ->orderBy('a.attribute_name')
            ->limit(self::MAX_ROWS + 1)
            ->get();

        if ($aggregates->count() > self::MAX_ROWS) {
            abort(422, 'The selected filters return too many rows — narrow the filters.');
        }

        $prices = $withPrice ? $this->resolvePrices($aggregates) : [];

        $rows = $aggregates->map(function ($agg) use ($withPrice, $prices): array {
            $row = [
                'product_id'     => (int) $agg->product_id,
                'attribute_id'   => $agg->attribute_id !== null ? (int) $agg->attribute_id : null,
                'product_code'   => $agg->product_code,
                'product_name'   => $agg->product_name,
                'category_name'  => $agg->category_name,
                'attribute_name' => $agg->attribute_name,
                'unit'           => $agg->unit,
                'available_qty'  => (float) $agg->available_qty,
            ];

            if ($withPrice) {
                $row['selling_price'] = $prices[$this->key((int) $agg->product_id, $agg->attribute_id)] ?? null;
            }

            return $row;
        })->all();

        return [
            'header'  => $this->buildHeader($productId, $categoryId, $attributeId, $locationId, $withPrice, count($rows)),
            'rows'    => $rows,
            'summary' => [
                'row_count'             => count($rows),
                'include_selling_price' => $withPrice,
            ],
        ];
    }

    /**
     * Selling price per product+colour for the whole result set, in two batched queries:
     * newest confirmed costing first, product price list as the fallback.
     *
     * @param Collection<int, object> $aggregates
     * @return array<string, float|null>
     */
    private function resolvePrices(Collection $aggregates): array
    {
        $productIds = $aggregates->pluck('product_id')->unique()->values()->all();

        if ($productIds === []) {
            return [];
        }

        // ── 1. Newest confirmed costing per (product, colour) ────────────────────
        // MAX(id) rather than a direct join: a product is costed once per shipment, so
        // joining every costing line would fan out a row per shipment instead of naming
        // the price the latest one sells at.
        $latestCostingIds = DB::table('inv_costing_items as ci')
            ->join('inv_costings as co', 'co.id', '=', 'ci.costing_id')
            ->where('co.status', CostingStatus::Confirmed->value)
            ->whereNull('co.deleted_at')
            ->whereIn('ci.product_id', $productIds)
            ->selectRaw('MAX(ci.id) as id')
            ->groupBy('ci.product_id', 'ci.attribute_id');

        $costed = [];

        DB::table('inv_costing_items as ci')
            ->joinSub($latestCostingIds, 'lci', 'lci.id', '=', 'ci.id')
            ->get(['ci.product_id', 'ci.attribute_id', 'ci.selling_price_base'])
            ->each(function ($row) use (&$costed): void {
                if ($row->selling_price_base !== null) {
                    $costed[$this->key((int) $row->product_id, $row->attribute_id)] = (float) $row->selling_price_base;
                }
            });

        // ── 2. Price-list fallback, one row per product ──────────────────────────
        // MIN(id) mirrors ProductPricingService::sellingPriceFor's orderBy('id') — the
        // primary (first) price-list row is the product's default selling price.
        $primaryChannelIds = DB::table('inv_product_sales_channels')
            ->whereIn('product_id', $productIds)
            ->selectRaw('MIN(id) as id')
            ->groupBy('product_id');

        $listed = DB::table('inv_product_sales_channels as psc')
            ->joinSub($primaryChannelIds, 'pc', 'pc.id', '=', 'psc.id')
            ->get(['psc.product_id', 'psc.selling_price', 'psc.unit_type_id'])
            ->keyBy('product_id');

        // ── 3. Resolve each row: costing first, converted price list second ──────
        $prices = [];

        foreach ($aggregates as $agg) {
            $key = $this->key((int) $agg->product_id, $agg->attribute_id);

            if (array_key_exists($key, $costed)) {
                $prices[$key] = $costed[$key];
                continue;
            }

            $channel = $listed->get($agg->product_id);

            $prices[$key] = $channel === null || $channel->selling_price === null
                ? null
                : $this->toBasePrice(
                    (float) $channel->selling_price,
                    $channel->unit_type_id !== null ? (int) $channel->unit_type_id : null,
                    $agg->base_unit_type_id !== null ? (int) $agg->base_unit_type_id : null,
                );
        }

        return $prices;
    }

    /**
     * Re-express a price-list price per the product's stocking UOM — the same rule
     * ProductPricingService applies, memoised across the report so a 500-row list costs
     * one factor lookup per distinct unit pair rather than 500.
     *
     * Null when no rate exists: better no price than a confidently wrong one.
     */
    private function toBasePrice(float $price, ?int $unitId, ?int $baseUnitId): ?float
    {
        if ($unitId === null || $baseUnitId === null || $unitId === $baseUnitId) {
            return $price;
        }

        $cacheKey = "{$unitId}|{$baseUnitId}";

        if (!array_key_exists($cacheKey, $this->factorCache)) {
            $this->factorCache[$cacheKey] = $this->units->tryFactor($unitId, $baseUnitId);
        }

        $factor = $this->factorCache[$cacheKey];

        return $factor !== null ? $this->units->priceToBase($price, $factor) : null;
    }

    /** Colourless rows key on 0 — NULL is not a usable array key, and 0 is not a valid attribute id. */
    private function key(int $productId, mixed $attributeId): string
    {
        return $productId . '|' . ($attributeId === null ? '0' : (int) $attributeId);
    }

    /** @return array<string, mixed> */
    private function buildHeader(
        ?int $productId,
        ?int $categoryId,
        ?int $attributeId,
        ?int $locationId,
        bool $withPrice,
        int $rowCount,
    ): array {
        $location  = $locationId ? Location::with('company')->find($locationId) : null;
        $category  = $categoryId ? DB::table('inv_categories')->where('id', $categoryId)->first(['category_name']) : null;
        $attribute = $attributeId ? DB::table('inv_attributes')->where('id', $attributeId)->first(['attribute_name']) : null;
        $product   = $productId ? DB::table('inv_products')->where('id', $productId)->first(['product_code', 'name']) : null;

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
            'attribute_name'  => $attribute->attribute_name ?? null,
            'product_name'    => $product ? trim("{$product->product_code} - {$product->name}") : null,
            'include_selling_price' => $withPrice,
            'row_count'       => $rowCount,
            'generated_by'    => Auth::user()?->name,
            'generated_at'    => now()->toDateTimeString(),
        ];
    }
}
