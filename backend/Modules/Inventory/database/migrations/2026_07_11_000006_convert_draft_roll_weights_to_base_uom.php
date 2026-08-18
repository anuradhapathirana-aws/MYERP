<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Services\UnitConversionService;
use Modules\Inventory\Support\Quantity;

return new class extends Migration
{
    /**
     * A roll's weight used to mean the line's UOM while the GRN was a draft and the
     * product's stocking UOM once it was confirmed — the same column carrying two units
     * depending on status. Rolls are now stored in the stocking UOM from the first save,
     * so the drafts still sitting in the old unit are converted here.
     *
     * Confirmed rolls are already in base units (they were converted at confirmation) and
     * are left alone. A draft whose UOM has no conversion rate is skipped rather than
     * guessed at — confirming it already fails with a clear 422.
     */
    public function up(): void
    {
        $this->rescaleDraftRolls(fn (float $weight, float $factor) => $weight * $factor);
    }

    public function down(): void
    {
        $this->rescaleDraftRolls(fn (float $weight, float $factor) => $weight / $factor);
    }

    private function rescaleDraftRolls(callable $apply): void
    {
        $units = app(UnitConversionService::class);

        $rolls = DB::table('inv_grn_item_pieces as p')
            ->join('inv_goods_received_note_items as i', 'i.id', '=', 'p.grn_item_id')
            ->join('inv_products as pr', 'pr.id', '=', 'i.product_id')
            ->where('p.status', 'draft')
            ->whereNotNull('p.weight')
            ->whereNotNull('i.unit_id')
            ->whereNotNull('pr.base_unit_type_id')
            ->whereColumn('i.unit_id', '!=', 'pr.base_unit_type_id')
            ->select('p.id', 'p.weight', 'i.unit_id', 'pr.base_unit_type_id')
            ->get();

        $factors = [];

        foreach ($rolls as $roll) {
            $key = "{$roll->unit_id}:{$roll->base_unit_type_id}";

            $factors[$key] ??= $units->tryFactor((int) $roll->unit_id, (int) $roll->base_unit_type_id);

            if ($factors[$key] === null) {
                continue;
            }

            DB::table('inv_grn_item_pieces')
                ->where('id', $roll->id)
                ->update([
                    'weight' => Quantity::round($apply((float) $roll->weight, $factors[$key])),
                ]);
        }
    }
};
