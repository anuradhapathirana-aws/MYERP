<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_customer_return_pieces', function (Blueprint $table): void {
            $table->id();

            // Soft links
            $table->unsignedBigInteger('return_id');
            $table->unsignedBigInteger('return_item_id');
            $table->unsignedBigInteger('do_piece_id'); // inv_delivery_order_pieces
            $table->unsignedBigInteger('piece_id');    // the delivered roll (inv_grn_item_pieces)

            // Snapshot of the delivered roll's code
            $table->string('piece_code', 40);

            // Entered in the line's UOM; returned_quantity is the stocking-UOM figure
            $table->decimal('quantity', 15, 4);
            $table->decimal('returned_quantity', 20, 6);

            // Stamped at confirm: the roll now holding the returned goods (the same roll
            // when it came back whole, otherwise a new "-R" roll), and its ledger row.
            $table->unsignedBigInteger('restored_piece_id')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->unsignedBigInteger('stock_transaction_id')->nullable();

            $table->timestamps();

            $table->index('return_id');
            $table->index('return_item_id');
            $table->index('do_piece_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_customer_return_pieces');
    }
};
