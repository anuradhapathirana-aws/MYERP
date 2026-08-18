<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The unit a line's unit_price is quoted PER — independent of unit_id (the
     * quantity's unit). Fabric bought by the metre is quoted per metre even when
     * the customer buys yards: 100 yd x 500/m bills 50,000 while only 91.44 m
     * leave stock. line_total stays quantity x unit_price with NO conversion.
     *
     * NULL = legacy line: the price is per the quantity's unit (unit_id).
     * Soft link (no FK) per the cross-module migration rule.
     */
    public function up(): void
    {
        Schema::table('inv_sales_order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('price_unit_id')->nullable()->after('unit_id');
        });

        Schema::table('inv_invoice_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('price_unit_id')->nullable()->after('unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('inv_sales_order_items', function (Blueprint $table): void {
            $table->dropColumn('price_unit_id');
        });

        Schema::table('inv_invoice_items', function (Blueprint $table): void {
            $table->dropColumn('price_unit_id');
        });
    }
};
