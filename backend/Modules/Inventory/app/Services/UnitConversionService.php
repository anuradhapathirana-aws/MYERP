<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Product;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Modules\Inventory\Models\UnitCategory;
use Modules\Inventory\Models\UnitConversion;
use Modules\Inventory\Models\UnitType;
use Modules\Inventory\Support\Quantity;

class UnitConversionService
{
    /**
     * factor(), but null instead of an abort when the units cannot be converted.
     *
     * Price lookups feed defaults on a form, and a whole roll list should not 500 because
     * one product is missing a rate. The caller decides what an unconvertible price means
     * — here it means "no default", never "use the number anyway", which would quietly
     * quote a per-metre price to a customer buying yards.
     */
    public function tryFactor(int $fromUnitId, int $toUnitId): ?float
    {
        try {
            return $this->factor($fromUnitId, $toUnitId);
        } catch (HttpException) {
            return null;
        }
    }

    /**
     * Rebase a document price so it lines up with a base-unit quantity.
     *
     * Documents quote the price the way the invoice does — 250 per Kg — but the ledger
     * counts in base units, so it must hold 0.25 per g. The two always travel together:
     * scaling the quantity by the factor while leaving the price alone would inflate
     * every stock valuation by exactly that factor.
     */
    public function priceToBase(float $price, float $factor): float
    {
        if ($factor <= 0.0) {
            return 0.0;
        }

        return Quantity::roundPrice($price / $factor);
    }

    /**
     * Multiplier that turns a quantity in $fromUnitId into $toUnitId.
     *
     * Aborts rather than guessing: a silent 1:1 fallback is what corrupts a stock
     * ledger, because the wrong number posts without anyone noticing.
     */
    public function factor(int $fromUnitId, int $toUnitId): float
    {
        // saveRates() never writes a self-referencing row, so identity is implicit.
        if ($fromUnitId === $toUnitId) {
            return 1.0;
        }

        $from = UnitType::find($fromUnitId, ['id', 'name', 'symbol', 'unit_category_id']);
        $to   = UnitType::find($toUnitId, ['id', 'name', 'symbol', 'unit_category_id']);

        abort_if($from === null || $to === null, 422, 'Unknown unit of measure.');

        // Conversion rates are only ever defined within a unit category — there is
        // no factor from Litre to Kg without a per-product density.
        abort_if(
            $from->unit_category_id !== $to->unit_category_id,
            422,
            sprintf(
                'Cannot convert %s to %s — they belong to different unit categories.',
                $from->symbol ?? $from->name,
                $to->symbol ?? $to->name,
            ),
        );

        $multiplier = UnitConversion::where('from_unit_type_id', $fromUnitId)
            ->where('to_unit_type_id', $toUnitId)
            ->value('multiplier');

        abort_if(
            $multiplier === null || (float) $multiplier <= 0.0,
            422,
            sprintf(
                'No conversion defined from %s to %s — set the rate in Unit Conversions.',
                $from->symbol ?? $from->name,
                $to->symbol ?? $to->name,
            ),
        );

        return (float) $multiplier;
    }

    /**
     * Converts a document quantity into the product's stocking (base) UOM.
     *
     * Everything that touches a balance — inv_stock_transactions, current_stock,
     * Batch.current_qty, roll weights — must go through here, so that a GRN in Kg
     * and a sale in g act on the same number line.
     *
     * @return array{qty: float, factor: float, base_unit_id: int}
     */
    public function toBase(Product $product, ?int $unitId, float $qty): array
    {
        $baseUnitId = $this->baseUnitIdFor($product);

        // A line saved before the UOM column existed is already in base units.
        $factor  = $unitId === null ? 1.0 : $this->factor($unitId, $baseUnitId);
        $baseQty = Quantity::round($qty * $factor);

        // A quantity that survives validation but rounds away to nothing would post
        // a zero movement while the document still claims stock changed hands.
        abort_if(
            $qty > 0.0 && $baseQty <= 0.0,
            422,
            sprintf(
                'Quantity %s is too small to record in the stocking UOM — it rounds to zero.',
                Quantity::format($qty),
            ),
        );

        return ['qty' => $baseQty, 'factor' => $factor, 'base_unit_id' => $baseUnitId];
    }

    /** The unit every balance for this product is denominated in. */
    public function baseUnitIdFor(Product $product): int
    {
        abort_if(
            $product->base_unit_type_id === null,
            422,
            sprintf(
                'Product "%s" has no Stocking UOM — set it on the product before moving stock.',
                $product->name,
            ),
        );

        return (int) $product->base_unit_type_id;
    }

    /** True when $unitId can be used on a document line for $product. */
    public function isUsableFor(Product $product, ?int $unitId): bool
    {
        if ($unitId === null || $product->base_unit_type_id === null) {
            return false;
        }

        return UnitType::where('id', $unitId)
            ->where(
                'unit_category_id',
                UnitType::where('id', $product->base_unit_type_id)->value('unit_category_id'),
            )
            ->exists();
    }

    public function getByCategoryWithRates(int $categoryId): array
    {
        $category = UnitCategory::find($categoryId, ['id', 'base_unit_type_id']);

        $unitTypes = UnitType::where('unit_category_id', $categoryId)
            ->orderBy('name')
            ->get(['id', 'name', 'symbol', 'created_at']);

        if ($unitTypes->isEmpty()) {
            return ['base_unit_id' => null, 'units' => []];
        }

        $baseUnitId = $category?->base_unit_type_id;

        // Map: to_unit_type_id => multiplier for base→other conversions
        $ratesFromBase = [];
        if ($baseUnitId) {
            $unitIds = $unitTypes->pluck('id')->toArray();
            $ratesFromBase = UnitConversion::where('from_unit_type_id', $baseUnitId)
                ->whereIn('to_unit_type_id', $unitIds)
                ->get(['to_unit_type_id', 'multiplier'])
                ->mapWithKeys(fn ($c) => [$c->to_unit_type_id => (float) $c->multiplier])
                ->all();
        }

        $units = $unitTypes->map(fn ($unit) => [
            'id'         => $unit->id,
            'name'       => $unit->name,
            'symbol'     => $unit->symbol,
            'created_at' => $unit->created_at?->format('d/m/Y'),
            'is_base'    => $baseUnitId !== null && $unit->id === $baseUnitId,
            'rate'       => ($baseUnitId !== null && $unit->id !== $baseUnitId)
                ? ($ratesFromBase[$unit->id] ?? null)
                : null,
        ]);

        return [
            'base_unit_id' => $baseUnitId,
            'units'        => $units->values()->all(),
        ];
    }

    /**
     * Delete all existing conversions for the category and re-save.
     * Stores bidirectional pairs: base→unit (rate) and unit→base (1/rate).
     */
    public function saveRates(int $categoryId, int $baseUnitTypeId, array $rates): void
    {
        $unitIds = UnitType::where('unit_category_id', $categoryId)->pluck('id')->toArray();

        DB::transaction(function () use ($categoryId, $unitIds, $baseUnitTypeId, $rates): void {
            UnitCategory::where('id', $categoryId)
                ->update(['base_unit_type_id' => $baseUnitTypeId]);

            UnitConversion::whereIn('from_unit_type_id', $unitIds)
                ->whereIn('to_unit_type_id', $unitIds)
                ->delete();

            foreach ($rates as $rate) {
                $unitTypeId = (int) $rate['unit_type_id'];
                $multiplier = (float) $rate['rate'];

                if ($multiplier <= 0.0) {
                    continue;
                }

                UnitConversion::create([
                    'from_unit_type_id' => $baseUnitTypeId,
                    'to_unit_type_id'   => $unitTypeId,
                    'multiplier'        => $multiplier,
                ]);

                UnitConversion::create([
                    'from_unit_type_id' => $unitTypeId,
                    'to_unit_type_id'   => $baseUnitTypeId,
                    'multiplier'        => 1.0 / $multiplier,
                ]);
            }
        });
    }
}
