<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quantities are now held in the product's base UOM, so a line entered in a
     * coarse unit can land as a small base-unit fraction (and vice versa).
     * decimal(15,4) is too tight for that: selling 1 g of a Kg-stocked product
     * needs 0.001 Kg, and finer ratios would round to zero. 6 dp gives headroom
     * for any g/Kg/MT combination.
     */
    public function up(): void
    {
        Schema::table('inv_product_location_stores', function (Blueprint $table): void {
            $table->decimal('current_stock', 20, 6)->default(0)->change();
        });

        Schema::table('inv_batches', function (Blueprint $table): void {
            $table->decimal('initial_qty', 20, 6)->default(0)->change();
            $table->decimal('current_qty', 20, 6)->default(0)->change();
        });

        Schema::table('inv_stock_transactions', function (Blueprint $table): void {
            $table->decimal('qty_in', 20, 6)->default(0)->change();
            $table->decimal('qty_out', 20, 6)->default(0)->change();
        });

        Schema::table('inv_grn_item_pieces', function (Blueprint $table): void {
            $table->decimal('weight', 20, 6)->nullable()->change();
        });

        Schema::table('inv_sales_order_pieces', function (Blueprint $table): void {
            $table->decimal('weight', 20, 6)->default(0)->change();
        });

        Schema::table('inv_delivery_order_pieces', function (Blueprint $table): void {
            $table->decimal('weight', 20, 6)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('inv_product_location_stores', function (Blueprint $table): void {
            $table->decimal('current_stock', 15, 4)->default(0)->change();
        });

        Schema::table('inv_batches', function (Blueprint $table): void {
            $table->decimal('initial_qty', 15, 4)->default(0)->change();
            $table->decimal('current_qty', 15, 4)->default(0)->change();
        });

        Schema::table('inv_stock_transactions', function (Blueprint $table): void {
            $table->decimal('qty_in', 15, 4)->default(0)->change();
            $table->decimal('qty_out', 15, 4)->default(0)->change();
        });

        Schema::table('inv_grn_item_pieces', function (Blueprint $table): void {
            $table->decimal('weight', 15, 4)->nullable()->change();
        });

        Schema::table('inv_sales_order_pieces', function (Blueprint $table): void {
            $table->decimal('weight', 15, 4)->default(0)->change();
        });

        Schema::table('inv_delivery_order_pieces', function (Blueprint $table): void {
            $table->decimal('weight', 15, 4)->default(0)->change();
        });
    }
};
