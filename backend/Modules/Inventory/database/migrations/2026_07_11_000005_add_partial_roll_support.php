<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Partial roll sales: a 100 m roll can be cut and 50 m sold, with the remnant
     * returning to stock as its own labelled roll.
     *
     * Until now `weight` on the SO/DO piece rows meant two things at once — the roll's
     * weight AND the quantity being sold — because a roll could only ship whole. Those
     * split apart here: `weight` stays the roll's full (reserved) weight, and
     * `taken_quantity` is the slice this line actually sells. Both in the product's
     * stocking UOM, which is what the stock ledger counts in.
     */
    public function up(): void
    {
        Schema::table('inv_sales_order_pieces', function (Blueprint $table): void {
            $table->decimal('taken_quantity', 20, 6)->default(0)->after('weight');
        });

        Schema::table('inv_delivery_order_pieces', function (Blueprint $table): void {
            $table->decimal('taken_quantity', 20, 6)->default(0)->after('weight');
        });

        // Existing allocations pre-date cutting, so they take the whole roll.
        DB::table('inv_sales_order_pieces')->update(['taken_quantity' => DB::raw('weight')]);
        DB::table('inv_delivery_order_pieces')->update(['taken_quantity' => DB::raw('weight')]);

        Schema::table('inv_grn_item_pieces', function (Blueprint $table): void {
            // The roll this one was cut from — null for rolls received at GRN.
            $table->unsignedBigInteger('parent_piece_id')->nullable()->after('piece_no');
            $table->index('parent_piece_id');
        });
    }

    public function down(): void
    {
        Schema::table('inv_sales_order_pieces', function (Blueprint $table): void {
            $table->dropColumn('taken_quantity');
        });

        Schema::table('inv_delivery_order_pieces', function (Blueprint $table): void {
            $table->dropColumn('taken_quantity');
        });

        Schema::table('inv_grn_item_pieces', function (Blueprint $table): void {
            $table->dropIndex(['parent_piece_id']);
            $table->dropColumn('parent_piece_id');
        });
    }
};
