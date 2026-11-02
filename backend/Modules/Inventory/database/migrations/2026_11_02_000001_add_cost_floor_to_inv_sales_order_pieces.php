<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Below-cost guard basis per allocated roll, snapshotted beside grn_unit_price:
 * the roll's break-even AFTER-tax price per stocking UOM — landed cost (purchase
 * + expenses) with its costing's SSCL and VAT, no margin. Null on rolls
 * allocated before this column, and the guard then falls back to grn_unit_price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_sales_order_pieces', function (Blueprint $table): void {
            if (! Schema::hasColumn('inv_sales_order_pieces', 'cost_floor')) {
                $table->decimal('cost_floor', 20, 8)->nullable()->after('grn_unit_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inv_sales_order_pieces', function (Blueprint $table): void {
            $table->dropColumn('cost_floor');
        });
    }
};
