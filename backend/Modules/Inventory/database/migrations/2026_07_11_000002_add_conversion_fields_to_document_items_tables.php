<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Document lines keep the quantity and unit the user actually entered, and now
     * also snapshot the conversion used to reach the product's base UOM.
     *
     * The factor is frozen per line on purpose: inv_unit_conversions rates are
     * editable at any time via UnitConversionService::saveRates(), so without a
     * snapshot a later rate correction would silently change what every historical
     * document meant.
     *
     * @var array<string, string> table => source quantity column
     */
    private const TABLES = [
        'inv_goods_received_note_items' => 'quantity_received',
        'inv_sales_order_items'         => 'quantity',
        'inv_delivery_order_items'      => 'quantity',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $qtyColumn) {
            Schema::table($table, function (Blueprint $blueprint) use ($qtyColumn): void {
                $blueprint->decimal('conversion_factor', 20, 10)->default(1)->after($qtyColumn);
                $blueprint->decimal('base_quantity', 20, 6)->default(0)->after('conversion_factor');
            });

            // Pre-existing lines were posted raw, so they are already in base UOM.
            DB::table($table)->update([
                'conversion_factor' => 1,
                'base_quantity'     => DB::raw($qtyColumn),
            ]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn(['conversion_factor', 'base_quantity']);
            });
        }
    }
};
