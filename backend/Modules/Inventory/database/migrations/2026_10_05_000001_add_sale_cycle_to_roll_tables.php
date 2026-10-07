<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A roll could be sold only once, ever: inv_sales_order_pieces and inv_delivery_order_pieces
 * keep their rows as sales history, and both carried UNIQUE(piece_id). A customer return
 * that brings a whole roll back puts that same roll in stock again (standard lot/serial
 * behaviour — its sticker stays valid), so it must be sellable again.
 *
 * sale_cycle counts how many times a roll has come back whole. Each SO/DO roll row records
 * the cycle it was made in, and uniqueness becomes (piece_id, sale_cycle): still one sales
 * order and one delivery order per roll at a time, but a fresh pair after every return.
 * Every existing row is cycle 0, so nothing already recorded changes meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_grn_item_pieces', function (Blueprint $table): void {
            $table->unsignedInteger('sale_cycle')->default(0)->after('status');
        });

        foreach (['inv_sales_order_pieces', 'inv_delivery_order_pieces'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedInteger('sale_cycle')->default(0)->after('piece_id');
            });

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropUnique("{$tableName}_piece_id_unique");
                $table->unique(['piece_id', 'sale_cycle'], "{$tableName}_piece_cycle_unique");
            });
        }
    }

    public function down(): void
    {
        foreach (['inv_sales_order_pieces', 'inv_delivery_order_pieces'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropUnique("{$tableName}_piece_cycle_unique");
                // Fails if a roll has been resold after a return — those rows cannot fit
                // the old one-sale-per-roll rule, and must be resolved before rolling back.
                $table->unique('piece_id');
            });

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('sale_cycle');
            });
        }

        Schema::table('inv_grn_item_pieces', function (Blueprint $table): void {
            $table->dropColumn('sale_cycle');
        });
    }
};
