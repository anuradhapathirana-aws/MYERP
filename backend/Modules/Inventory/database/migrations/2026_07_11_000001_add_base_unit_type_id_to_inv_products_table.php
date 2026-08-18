<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The product's stocking (base) UOM — the single unit every stock balance is
     * denominated in. Documents may transact in any unit of the same unit category
     * and are converted into this unit at confirm time via inv_unit_conversions.
     */
    public function up(): void
    {
        Schema::table('inv_products', function (Blueprint $table): void {
            $table->foreignId('base_unit_type_id')
                ->nullable()
                ->after('location_id')
                ->constrained('inv_unit_types')
                ->nullOnDelete();
        });

        $this->backfill();
    }

    /**
     * Existing stock was posted raw, in whatever unit its documents used — so the
     * unit a product was last received in IS the unit its current_stock is already
     * denominated in. Adopting it as the base UOM leaves every existing balance
     * numerically correct. Falls back to the price list, then to the unit
     * category's own base unit.
     */
    private function backfill(): void
    {
        foreach (DB::table('inv_products')->pluck('id') as $productId) {
            $unitId = DB::table('inv_goods_received_note_items as gi')
                ->join('inv_goods_received_notes as g', 'g.id', '=', 'gi.grn_id')
                ->where('gi.product_id', $productId)
                ->where('g.status', 'confirmed')
                ->whereNotNull('gi.unit_id')
                ->orderByDesc('gi.id')
                ->value('gi.unit_id');

            $unitId ??= DB::table('inv_product_sales_channels')
                ->where('product_id', $productId)
                ->whereNotNull('unit_type_id')
                ->orderBy('id')
                ->value('unit_type_id');

            $unitId ??= DB::table('inv_unit_categories')
                ->whereNotNull('base_unit_type_id')
                ->where('is_default', true)
                ->value('base_unit_type_id');

            if ($unitId !== null) {
                DB::table('inv_products')
                    ->where('id', $productId)
                    ->update(['base_unit_type_id' => $unitId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('inv_products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('base_unit_type_id');
        });
    }
};
