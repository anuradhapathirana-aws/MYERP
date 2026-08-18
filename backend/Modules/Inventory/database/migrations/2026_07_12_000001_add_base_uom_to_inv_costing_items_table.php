<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A costing line now carries BOTH denominations of its price build-up.
 *
 * The build-up is computed per the GRN's receiving unit, because that is the unit the
 * supplier quoted and the only one in which `unit_price + charge_portion` is a legal
 * addition. But the price list, the sales order and the invoice all speak the product's
 * stocking (base) UOM, so the line also stores what that same money means per base unit —
 * 27,500 per Roll IS 550 per Kg, and only one of those two numbers can be shown to a
 * customer buying by weight.
 *
 * The factor is snapshotted from the GRN line rather than recomputed. That line froze it
 * at confirm time precisely because inv_unit_conversions rates stay editable, and a
 * costing must value a shipment the way the stock ledger already valued it.
 */
return new class extends Migration
{
    /** @var array<int, string> per-unit money columns, which shrink by the factor in base UOM */
    private const PRICE_COLUMNS = [
        'unit_price',
        'charge_portion',
        'landed_unit_cost',
        'margin_amount',
        'sscl_amount',
        'vat_amount',
        'selling_price',
    ];

    public function up(): void
    {
        Schema::table('inv_costing_items', function (Blueprint $table): void {
            $table->decimal('conversion_factor', 20, 10)->default(1)->after('quantity');
            $table->decimal('base_quantity', 20, 6)->default(0)->after('conversion_factor');
            $table->unsignedBigInteger('base_unit_id')->nullable()->after('base_quantity');
            $table->decimal('landed_unit_cost_base', 20, 8)->default(0)->after('landed_unit_cost');
            $table->decimal('selling_price_base', 20, 8)->default(0)->after('selling_price');
        });

        // Same reasoning as widen_base_uom_price_decimals: a price per base UOM is the
        // per-receiving-unit price divided by the factor, and decimal(15,4) cannot hold
        // the tail of that division — an item priced per tonne rounds to 0.0000 per gram.
        $this->setScale(20, 8);

        // Rows costed before this migration were priced in the receiving unit with no
        // conversion recorded, so for them the receiving unit IS the base unit.
        DB::table('inv_costing_items')->update([
            'conversion_factor'     => 1,
            'base_quantity'         => DB::raw('quantity'),
            'base_unit_id'          => DB::raw('unit_id'),
            'landed_unit_cost_base' => DB::raw('landed_unit_cost'),
            'selling_price_base'    => DB::raw('selling_price'),
        ]);
    }

    public function down(): void
    {
        $this->setScale(15, 4);

        Schema::table('inv_costing_items', function (Blueprint $table): void {
            $table->dropColumn([
                'conversion_factor',
                'base_quantity',
                'base_unit_id',
                'landed_unit_cost_base',
                'selling_price_base',
            ]);
        });
    }

    private function setScale(int $total, int $places): void
    {
        Schema::table('inv_costing_items', function (Blueprint $table) use ($total, $places): void {
            foreach (self::PRICE_COLUMNS as $column) {
                $table->decimal($column, $total, $places)->default(0)->change();
            }
        });
    }
};
