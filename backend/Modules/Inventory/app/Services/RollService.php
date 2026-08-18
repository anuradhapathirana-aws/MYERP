<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Modules\Inventory\Models\GrnItemPiece;
use Modules\Inventory\Support\Quantity;

/**
 * Everything that happens to a roll as a physical object: how a sale is spread across
 * the rolls it draws from, and what becomes of a roll that is only partly sold.
 *
 * This lives apart from SalesOrderService and DeliveryOrderService because it is not
 * about sales at all — a stock adjustment, a customer return or a manual re-cut would
 * need exactly the same behaviour, and none of them should have to reach into a sales
 * service to get it.
 *
 * Every quantity here is in the product's stocking (base) UOM, because that is the unit
 * a roll's weight is sealed in at GRN confirm.
 */
class RollService
{
    /** Appended to a roll's code when it is cut: "…-P003" becomes "…-P003-C1". */
    private const CUT_SUFFIX = 'C';

    /**
     * Spread a sale across its rolls, filling the oldest first so that at most one roll
     * — the last one reached — is left partly used.
     *
     * @param  Collection<int, GrnItemPiece> $rolls        in the order they should be consumed
     * @param  float                         $baseQuantity total to take, in the stocking UOM
     * @return array<int, float>                           roll id => quantity taken
     */
    public function distribute(Collection $rolls, float $baseQuantity, float $tolerance = Quantity::EPSILON): array
    {
        abort_if(
            ! Quantity::isPositive($baseQuantity),
            422,
            'Enter the quantity to sell from the selected rolls.',
        );

        $capacity = $this->capacityOf($rolls);

        abort_if(
            $baseQuantity - $capacity > $tolerance,
            422,
            sprintf(
                'The selected rolls hold only %s — add another roll or reduce the quantity.',
                Quantity::format($capacity),
            ),
        );

        $taken = [];
        // Asking for (near enough) everything means everything: the conversion cannot land
        // exactly on the roll's length, and the difference must not become a phantom roll.
        $remaining = min($baseQuantity, $capacity);

        foreach ($rolls as $roll) {
            $taken[$roll->id] = $this->takeFrom($roll, $remaining, $tolerance);
            $remaining        = max(0.0, Quantity::round($remaining - $taken[$roll->id]));
        }

        // A roll the quantity never reached would be reserved out of stock while selling
        // nothing — almost certainly a mis-click, and it would strand the roll.
        foreach ($taken as $take) {
            abort_if(
                ! Quantity::isPositive($take),
                422,
                'One of the selected rolls is not needed for this quantity — remove it, or increase the quantity.',
            );
        }

        return $taken;
    }

    /**
     * How much of one roll a demand of $wanted consumes.
     *
     * If honouring $wanted exactly would leave behind less than the user could even type,
     * the whole roll goes: a 0.000008 m offcut is not stock, it is rounding dust, and
     * turning it into a labelled roll strands it in the warehouse forever.
     */
    private function takeFrom(GrnItemPiece $roll, float $wanted, float $tolerance): float
    {
        $weight = $this->weightOf($roll);
        $take   = min($wanted, $weight);

        if (! Quantity::isSellable($weight - $take)) {
            $take = $weight;
        }

        return Quantity::round($take);
    }

    /**
     * Per-roll amounts chosen explicitly in the roll picker, converted from the UOM the
     * customer buys in into the stocking UOM and checked against what each roll holds.
     *
     * @param  Collection<int, GrnItemPiece>   $rolls
     * @param  array<string, float|string>     $takes  roll code => amount, in the line's UOM
     * @param  float                           $factor line UOM -> stocking UOM
     * @return array<int, float>                       roll id => quantity taken
     */
    public function takesFor(Collection $rolls, array $takes, float $factor): array
    {
        $tolerance = Quantity::toleranceFor($factor);
        $taken     = [];

        foreach ($rolls as $roll) {
            $take   = Quantity::round((float) ($takes[$roll->piece_code] ?? 0) * $factor);
            $weight = $this->weightOf($roll);

            abort_if(
                ! Quantity::isPositive($take),
                422,
                "Enter how much to take from roll {$roll->piece_code}.",
            );

            abort_if(
                $take - $weight > $tolerance,
                422,
                sprintf(
                    'Roll %s holds only %s — cannot take %s.',
                    $roll->piece_code,
                    Quantity::format($weight),
                    Quantity::format($take),
                ),
            );

            $taken[$roll->id] = $this->takeFrom($roll, $take, $tolerance);
        }

        return $taken;
    }

    /**
     * Retire a roll that has been dispatched — and if the customer took only part of it,
     * cut it: the offcut becomes a new roll with its own code and QR.
     *
     * The remnant deliberately posts NO inbound stock movement. Its length arrived on the
     * original GRN and has been sitting in current_stock ever since; the dispatch only
     * decremented the part that was sold. Adding it back would double-count the stock.
     * A cut re-labels stock that is already there — it does not receive any.
     *
     * @param  float $taken quantity sold off this roll, in the stocking UOM
     * @return GrnItemPiece|null the remnant, or null when the roll shipped whole
     */
    public function shipOrCut(GrnItemPiece $roll, float $taken): ?GrnItemPiece
    {
        $remainder = Quantity::round($this->weightOf($roll) - $taken);

        // A leftover too small to type is not a remnant — it is conversion dust. Labelling
        // it would create a roll that can never be sold and clutters the picker forever.
        if (! Quantity::isSellable($remainder)) {
            $roll->update(['status' => GrnItemPiece::STATUS_DELIVERED]);

            return null;
        }

        $remnant = GrnItemPiece::create([
            'grn_item_id'          => $roll->grn_item_id,
            'grn_id'               => $roll->grn_id,
            'product_id'           => $roll->product_id,
            'parent_piece_id'      => $roll->id,
            'batch_id'             => $roll->batch_id,
            // Keeps the offcut tied to the ledger row that brought it into stock, so the
            // bin card can still trace it back to the GRN it arrived on.
            'stock_transaction_id' => $roll->stock_transaction_id,
            'store_id'             => $roll->store_id,
            'location_id'          => $roll->location_id,
            'piece_no'             => $roll->piece_no,
            'weight'               => $remainder,
            'roll_no'              => $roll->roll_no,
            'piece_code'           => $this->nextCutCode($roll),
            'status'               => GrnItemPiece::STATUS_IN_STOCK,
            // Never printed, so it surfaces on the label queue: the sticker on the
            // physical roll now overstates it and must be replaced.
            'printed_at'           => null,
            'created_by'           => Auth::id(),
        ]);

        $roll->update(['status' => GrnItemPiece::STATUS_DELIVERED]);

        return $remnant;
    }

    /** Total the given rolls hold, in the stocking UOM. */
    public function capacityOf(Collection $rolls): float
    {
        return Quantity::round(
            (float) $rolls->sum(fn (GrnItemPiece $roll) => $this->weightOf($roll)),
        );
    }

    private function weightOf(GrnItemPiece $roll): float
    {
        return (float) ($roll->weight ?? 0);
    }

    /**
     * "…-P003" cut once becomes "…-P003-C1", cut again "…-P003-C2".
     *
     * The suffix is stripped before counting so that cutting a remnant does not produce
     * "…-P003-C1-C1"; every offcut of the same original roll shares one series.
     */
    private function nextCutCode(GrnItemPiece $roll): string
    {
        $root    = preg_replace('/-' . self::CUT_SUFFIX . '\d+$/', '', (string) $roll->piece_code);
        $pattern = $root . '-' . self::CUT_SUFFIX;

        $existing = GrnItemPiece::where('piece_code', 'like', $pattern . '%')->count();

        return $pattern . ($existing + 1);
    }
}
