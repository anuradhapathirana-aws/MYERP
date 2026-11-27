<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Services\UnitConversionService;

/**
 * One-off switch of stored prices from AFTER TAX (VAT inside) to BEFORE TAX.
 *
 * Shared by the conversion migration (apply) and `inventory:net-pricing --dry-run`
 * (plan only, writes nothing — also works before the new columns exist).
 *
 * Price list rows
 *   A row whose price is exactly what the newest confirmed costing synced gets that
 *   costing's Before Tax price ("costing" — exact). Any other row was typed by hand
 *   and is taken to carry VAT: ÷ (1 + default VAT) ("vat_stripped" — client verifies).
 *   Variant rows also get cost_price = the costing's landed cost.
 *
 * SO lines (every status — the line is the source an invoice bills from)
 *   The Before Tax price is chosen so the invoice still bills what it billed before:
 *     proforma   recalled from a Tax proforma: the proforma's own Before Tax price
 *     costing    a costed roll/qty line at the costing price: the costing's Before Tax
 *     typed      a costed line whose SO price was typed differently: SO price ÷ VAT
 *     vat_stripped  no costing: SO price ÷ (1 + default VAT) — exactly the old fallback
 *   The Before Tax price keeps 8 dp, so Before Tax × (1 + VAT) rounds back to the old
 *   SO price to the cent (Non Tax invoices bill the same money).
 *
 * Old values are kept in legacy_* columns; revert() restores them.
 */
final class NetPricingBackfill
{
    private const PRICE_MATCH = 0.0001;

    private const MONEY_MATCH = 0.005;

    public function __construct(private readonly UnitConversionService $units)
    {
    }

    // ── Price list ───────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function planPriceList(): array
    {
        $plans   = [];
        $hasCols = Schema::hasColumn('inv_product_sales_channels', 'net_price_source');

        $rows = DB::table('inv_product_sales_channels as pc')
            ->leftJoin('inv_products as p', 'p.id', '=', 'pc.product_id')
            ->whereNotNull('pc.selling_price')
            ->when($hasCols, fn ($q) => $q->whereNull('pc.net_price_source'))
            ->orderBy('pc.id')
            ->get(['pc.id', 'pc.product_id', 'pc.unit_type_id', 'pc.selling_price', 'p.product_code', 'p.name']);

        foreach ($rows as $row) {
            $costing = $this->newestCosting((int) $row->product_id, null);
            $plans[] = ['table' => 'inv_product_sales_channels', 'id' => (int) $row->id, 'variant_id' => null,
                'product' => trim(($row->product_code ?? '') . ' ' . ($row->name ?? ''))]
                + $this->convertListPrice((float) $row->selling_price, $row->unit_type_id, $costing);
        }

        $variantRows = DB::table('inv_product_variant_prices as vp')
            ->join('inv_product_variants as v', 'v.id', '=', 'vp.variant_id')
            ->leftJoin('inv_products as p', 'p.id', '=', 'v.product_id')
            ->whereNotNull('vp.selling_price')
            ->when($hasCols, fn ($q) => $q->whereNull('vp.net_price_source'))
            ->orderBy('vp.id')
            ->get(['vp.id', 'vp.variant_id', 'vp.sales_channel_id', 'vp.selling_price', 'v.product_id', 'v.sku', 'p.product_code', 'p.name']);

        foreach ($variantRows as $row) {
            // A variant price is filed in the unit of the product's row on the same channel
            $unitId = DB::table('inv_product_sales_channels')
                ->where('product_id', $row->product_id)
                ->where('sales_channel_id', $row->sales_channel_id)
                ->value('unit_type_id');

            $costing = $this->newestCosting((int) $row->product_id, (int) $row->variant_id);
            $plans[] = ['table' => 'inv_product_variant_prices', 'id' => (int) $row->id, 'variant_id' => (int) $row->variant_id,
                'product' => trim(($row->product_code ?? '') . ' ' . ($row->name ?? '') . ' / ' . $row->sku)]
                + $this->convertListPrice((float) $row->selling_price, $unitId, $costing);
        }

        return $plans;
    }

    /** @param array<int, array<string, mixed>> $plans */
    public function applyPriceList(array $plans): void
    {
        foreach ($plans as $plan) {
            $update = [
                'legacy_selling_price' => $plan['old_price'],
                'selling_price'        => $plan['new_price'],
                'net_price_source'     => $plan['source'],
            ];

            if ($plan['table'] === 'inv_product_variant_prices' && $plan['cost_price'] !== null) {
                $update['cost_price'] = $plan['cost_price'];
            }

            DB::table($plan['table'])->where('id', $plan['id'])->update($update);
        }
    }

    // ── SO lines ─────────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function planSalesOrderLines(): array
    {
        $hasCols = Schema::hasColumn('inv_sales_order_items', 'vat_pct');

        $lines = DB::table('inv_sales_order_items as si')
            ->join('inv_sales_orders as so', 'so.id', '=', 'si.so_id')
            ->leftJoin('inv_products as p', 'p.id', '=', 'si.product_id')
            ->when($hasCols, fn ($q) => $q->whereNull('si.vat_pct'))
            ->orderBy('si.id')
            ->get([
                'si.id', 'si.so_id', 'so.so_no', 'so.status', 'si.product_id', 'si.attribute_id', 'si.variant_id',
                'si.unit_id', 'si.price_unit_id', 'si.proforma_item_id', 'si.quantity', 'si.unit_price',
                'si.discount', 'si.tax', 'si.line_total', 'p.product_code', 'p.base_unit_type_id', 'p.stock_tracking',
            ]);

        return $lines->map(fn ($line) => $this->planLine($line))->all();
    }

    /** @param array<int, array<string, mixed>> $plans */
    public function applySalesOrderLines(array $plans): void
    {
        $soIds = [];

        foreach ($plans as $plan) {
            DB::table('inv_sales_order_items')->where('id', $plan['id'])->update([
                'legacy_unit_price' => $plan['old_price'],
                'legacy_tax'        => $plan['old_tax'],
                'legacy_line_total' => $plan['old_line_total'],
                'unit_price'        => $plan['new_price'],
                'vat_pct'           => $plan['vat_pct'],
                'tax'               => 0,
                'line_total'        => $plan['new_line_total'],
                'net_price_source'  => $plan['source'],
            ]);
            $soIds[$plan['so_id']] = true;
        }

        foreach (array_keys($soIds) as $soId) {
            $so = DB::table('inv_sales_orders')->where('id', $soId)->first(['grand_total', 'transport_charge', 'legacy_grand_total']);

            $lineTotals = (float) DB::table('inv_sales_order_items')->where('so_id', $soId)->sum('line_total');
            $vat        = (float) DB::table('inv_sales_order_items')->where('so_id', $soId)
                ->sum(DB::raw('line_total * COALESCE(vat_pct, 0) / 100'));

            DB::table('inv_sales_orders')->where('id', $soId)->update([
                'legacy_grand_total' => $so->legacy_grand_total ?? $so->grand_total,
                'vat_amount'         => round($vat, 4),
                'grand_total'        => round($lineTotals + $vat + (float) $so->transport_charge, 4),
            ]);
        }
    }

    // ── Revert ───────────────────────────────────────────────────────────────

    public function revert(): void
    {
        DB::table('inv_sales_order_items')->whereNotNull('net_price_source')->update([
            'unit_price'        => DB::raw('legacy_unit_price'),
            'tax'               => DB::raw('COALESCE(legacy_tax, 0)'),
            'line_total'        => DB::raw('legacy_line_total'),
            'vat_pct'           => null,
            'net_price_source'  => null,
            'legacy_unit_price' => null,
            'legacy_tax'        => null,
            'legacy_line_total' => null,
        ]);

        DB::table('inv_sales_orders')->whereNotNull('legacy_grand_total')->update([
            'grand_total'        => DB::raw('legacy_grand_total'),
            'vat_amount'         => 0,
            'legacy_grand_total' => null,
        ]);

        foreach (['inv_product_sales_channels', 'inv_product_variant_prices'] as $table) {
            DB::table($table)->whereNotNull('net_price_source')->update([
                'selling_price'        => DB::raw('legacy_selling_price'),
                'legacy_selling_price' => null,
                'net_price_source'     => null,
            ]);
        }
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * @param object{before:float, selling:float, landed:float, vat_pct:float, base_unit_id:?int}|null $costing
     * @return array{old_price: float, new_price: float, source: string, cost_price: ?float}
     */
    private function convertListPrice(float $price, mixed $rowUnitId, ?object $costing): array
    {
        if ($costing !== null) {
            $factor = $this->rowFactor($rowUnitId !== null ? (int) $rowUnitId : null, $costing->base_unit_id);

            if ($factor !== null) {
                $cost = Quantity::roundPrice($costing->landed * $factor);

                if (abs(Quantity::roundPrice($costing->selling * $factor) - $price) <= self::PRICE_MATCH) {
                    return [
                        'old_price'  => $price,
                        'new_price'  => Quantity::roundPrice($costing->before * $factor),
                        'source'     => 'costing',
                        'cost_price' => $cost,
                    ];
                }

                return ['old_price' => $price, 'new_price' => Quantity::roundPrice(Vat::net($price)), 'source' => 'vat_stripped', 'cost_price' => $cost];
            }
        }

        return ['old_price' => $price, 'new_price' => Quantity::roundPrice(Vat::net($price)), 'source' => 'vat_stripped', 'cost_price' => null];
    }

    /** Mirrors ProductPricingService::syncFromCosting — a row with no unit is in the stocking UOM. */
    private function rowFactor(?int $rowUnitId, ?int $baseUnitId): ?float
    {
        if ($rowUnitId === null || $baseUnitId === null || $rowUnitId === $baseUnitId) {
            return 1.0;
        }

        $factor = $this->units->tryFactor($rowUnitId, $baseUnitId);

        return $factor !== null && $factor > 0.0 ? $factor : null;
    }

    /**
     * The costing line that last synced the price list: newest confirmed costing,
     * last line of the product in it (confirm syncs lines in id order).
     */
    private function newestCosting(int $productId, ?int $variantId): ?object
    {
        $row = DB::table('inv_costing_items as ci')
            ->join('inv_costings as c', 'c.id', '=', 'ci.costing_id')
            ->where('ci.product_id', $productId)
            ->when($variantId === null, fn ($q) => $q->whereNull('ci.variant_id'), fn ($q) => $q->where('ci.variant_id', $variantId))
            ->where('c.status', 'confirmed')
            ->whereNull('c.deleted_at')
            ->orderByDesc('c.confirmed_at')
            ->orderByDesc('ci.id')
            ->first(['ci.before_tax_price_base', 'ci.selling_price_base', 'ci.landed_unit_cost_base', 'ci.base_unit_id',
                'ci.vat_pct as line_vat', 'c.vat_pct as header_vat', 'c.apply_vat']);

        return $row === null ? null : $this->costingObject($row);
    }

    private function costingObject(object $row): object
    {
        $pct = $row->apply_vat ? (float) ($row->line_vat ?? $row->header_vat) : 0.0;

        return (object) [
            'before'       => (float) ((float) $row->before_tax_price_base > 0 ? $row->before_tax_price_base : $row->selling_price_base),
            'selling'      => (float) $row->selling_price_base,
            'landed'       => (float) ($row->landed_unit_cost_base ?? 0),
            'vat_pct'      => $pct,
            'base_unit_id' => $row->base_unit_id !== null ? (int) $row->base_unit_id : null,
        ];
    }

    /** @return array<string, mixed> */
    private function planLine(object $line): array
    {
        $soPrice  = (float) $line->unit_price;
        $default  = Vat::defaultPct();
        [$net, $vat, $source] = [Vat::net($soPrice, $default), $default, 'vat_stripped'];
        // What a Tax invoice billed per unit before the switch (the old fallback)
        $oldTaxUnit = round(Vat::net($soPrice, $default), 2);

        $proforma = $line->proforma_item_id !== null ? DB::table('inv_proforma_invoice_items as pi')
            ->join('inv_proforma_invoices as pf', 'pf.id', '=', 'pi.proforma_id')
            ->where('pi.id', $line->proforma_item_id)
            ->first(['pi.unit_price', 'pi.tax', 'pf.invoice_type']) : null;

        if ($proforma !== null && $proforma->invoice_type === 'tax' && (float) $proforma->tax > 0
            && abs(round((float) $proforma->unit_price * (1 + (float) $proforma->tax / 100), 2) - $soPrice) <= self::MONEY_MATCH) {
            [$net, $vat, $source] = [(float) $proforma->unit_price, (float) $proforma->tax, 'proforma'];
            $oldTaxUnit = round((float) $proforma->unit_price, 2);
        } elseif ($proforma === null && ($costing = $this->lineCosting($line)) !== null) {
            $factor = $this->lineFactor($line);

            if ($factor !== null) {
                $pct = $costing->vat_pct;

                [$net, $vat, $source] = abs($costing->selling * $factor - $soPrice) <= self::MONEY_MATCH
                    ? [$costing->before * $factor, $pct, 'costing']
                    : [Vat::net($soPrice, $pct), $pct, 'typed'];

                // Old pricing: a roll line billed its costing's Before Tax whatever the SO
                // said; a quantity line billed a typed SO price ÷ the costing's VAT.
                $oldTaxUnit = round($source === 'typed' && $line->stock_tracking !== 'roll'
                    ? Vat::net($soPrice, $pct)
                    : $costing->before * $factor, 2);
            }
        }

        $net       = Quantity::roundPrice($net);
        $qty       = (float) $line->quantity;
        $disc      = (float) $line->discount;
        $lineTotal = $qty * $net * (1 - $disc / 100);

        return [
            'id'             => (int) $line->id,
            'so_id'          => (int) $line->so_id,
            'so_no'          => $line->so_no,
            'status'         => $line->status,
            'product'        => $line->product_code,
            'quantity'       => $qty,
            'discount'       => $disc,
            'old_price'      => $soPrice,
            'old_tax'        => (float) $line->tax,
            'old_line_total' => (float) $line->line_total,
            'new_price'      => $net,
            'vat_pct'        => $vat,
            'source'         => $source,
            'new_line_total' => round($lineTotal, 4),
            // What a Non Tax invoice bills per unit, before and after the switch
            'old_non_tax_unit' => round($soPrice, 2),
            'new_non_tax_unit' => round($net * (1 + $vat / 100), 2),
            'old_tax_unit'     => $oldTaxUnit,
            'new_tax_unit'     => round($net, 2),
        ];
    }

    /**
     * The confirmed costing behind an SO line — its rolls' GRN line when roll-tracked
     * (exactly one distinct price, as the old invoice pricing required), else the
     * newest costing of the product + colour / variant.
     */
    private function lineCosting(object $line): ?object
    {
        $grnItemIds = DB::table('inv_sales_order_pieces as sp')
            ->join('inv_grn_item_pieces as p', 'p.id', '=', 'sp.piece_id')
            ->where('sp.so_item_id', $line->id)
            ->whereNotNull('p.grn_item_id')
            ->distinct()
            ->pluck('p.grn_item_id');

        $query = DB::table('inv_costing_items as ci')
            ->join('inv_costings as c', 'c.id', '=', 'ci.costing_id')
            ->where('c.status', 'confirmed')
            ->whereNull('c.deleted_at');

        if ($grnItemIds->isNotEmpty()) {
            $rows = (clone $query)->whereIn('ci.grn_item_id', $grnItemIds)
                ->orderByDesc('ci.id')
                ->get(['ci.grn_item_id', 'ci.before_tax_price_base', 'ci.selling_price_base', 'ci.landed_unit_cost_base',
                    'ci.base_unit_id', 'ci.vat_pct as line_vat', 'c.vat_pct as header_vat', 'c.apply_vat'])
                ->unique('grn_item_id')
                ->map(fn ($row) => $this->costingObject($row))
                ->unique(fn ($c) => $c->before . '|' . $c->vat_pct . '|' . $c->selling)
                ->values();

            // None costed, or rolls from costings that disagree → no single price to trust
            return $rows->count() === 1 ? $rows->first() : null;
        }

        if ($line->stock_tracking === 'roll') {
            return null;
        }

        $row = $query->where('ci.product_id', $line->product_id)
            ->when(
                $line->variant_id !== null,
                fn ($q) => $q->where('ci.variant_id', $line->variant_id),
                fn ($q) => $q->when(
                    $line->attribute_id === null,
                    fn ($q) => $q->whereNull('ci.attribute_id'),
                    fn ($q) => $q->where('ci.attribute_id', $line->attribute_id),
                ),
            )
            ->whereNotNull('ci.grn_item_id')
            ->orderByDesc('ci.id')
            ->first(['ci.before_tax_price_base', 'ci.selling_price_base', 'ci.landed_unit_cost_base', 'ci.base_unit_id',
                'ci.vat_pct as line_vat', 'c.vat_pct as header_vat', 'c.apply_vat']);

        return $row === null ? null : $this->costingObject($row);
    }

    /** The unit the line quotes its price in (price_unit_id, else its quantity unit) → stocking UOM. */
    private function lineFactor(object $line): ?float
    {
        $unitId = $line->price_unit_id ?? $line->unit_id;
        $base   = $line->base_unit_type_id;

        if ($unitId === null || $base === null || (int) $unitId === (int) $base) {
            return 1.0;
        }

        $factor = $this->units->tryFactor((int) $unitId, (int) $base);

        return $factor !== null && $factor > 0.0 ? $factor : null;
    }
}
