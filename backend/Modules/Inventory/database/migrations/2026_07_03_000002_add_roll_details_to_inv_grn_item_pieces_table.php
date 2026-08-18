<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_grn_item_pieces', function (Blueprint $table): void {
            $table->decimal('weight', 15, 4)->nullable()->after('piece_no');
            $table->string('roll_no', 100)->nullable()->after('weight');
            $table->unsignedBigInteger('stock_transaction_id')->nullable()->after('batch_id');

            $table->index('stock_transaction_id');
        });

        // Pieces now get created at draft time (before a QR code is assigned), so
        // piece_code must allow NULL until confirm() seals them. Unique index still
        // holds — MySQL permits multiple NULLs under a unique constraint.
        Schema::table('inv_grn_item_pieces', function (Blueprint $table): void {
            $table->string('piece_code', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('inv_grn_item_pieces', function (Blueprint $table): void {
            $table->dropColumn(['weight', 'roll_no', 'stock_transaction_id']);
        });
    }
};
