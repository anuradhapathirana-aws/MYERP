<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Services\ProductPricingService;

/**
 * SO prices are now BEFORE TAX, so the below-cost floor snapshotted per allocated
 * roll must be too: landed cost + SSCL, no VAT. Rolls whose shipment has a confirmed
 * costing get the floor re-read from it; rolls floored at their GRN cost (never
 * costed) already carry no VAT and are left alone.
 *
 * down() is a no-op: the old floors were a derived snapshot, re-derivable by
 * re-allocating, and nothing is billed from them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pricing = app(ProductPricingService::class);

        $rows = DB::table('inv_sales_order_pieces as sp')
            ->join('inv_grn_item_pieces as p', 'p.id', '=', 'sp.piece_id')
            ->whereNotNull('sp.cost_floor')
            ->whereNotNull('p.grn_item_id')
            ->get(['sp.id', 'p.grn_item_id']);

        $floors = [];

        foreach ($rows as $row) {
            $grnItemId = (int) $row->grn_item_id;
            $floors[$grnItemId] ??= $pricing->costingPricingForGrnItem($grnItemId)['cost_floor'] ?? false;

            if ($floors[$grnItemId] !== false) {
                DB::table('inv_sales_order_pieces')->where('id', $row->id)->update(['cost_floor' => $floors[$grnItemId]]);
            }
        }
    }

    public function down(): void
    {
    }
};
