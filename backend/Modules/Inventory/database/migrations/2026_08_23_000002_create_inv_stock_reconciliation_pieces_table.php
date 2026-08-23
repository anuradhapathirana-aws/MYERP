<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_stock_reconciliation_pieces', function (Blueprint $table): void {
            $table->id();

            // Genuine parent-child relation to the reconciliation header — real FK.
            $table->foreignId('reconciliation_id')
                ->constrained('inv_stock_reconciliations')
                ->cascadeOnDelete();

            // Soft link to the existing roll being corrected (inv_grn_item_pieces).
            $table->unsignedBigInteger('grn_item_piece_id');

            $table->decimal('old_weight', 20, 6);
            $table->decimal('new_weight', 20, 6);

            $table->timestamps();

            $table->index('grn_item_piece_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_stock_reconciliation_pieces');
    }
};
