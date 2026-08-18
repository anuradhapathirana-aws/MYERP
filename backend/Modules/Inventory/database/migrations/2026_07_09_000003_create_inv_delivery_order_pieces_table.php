<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_delivery_order_pieces', function (Blueprint $table): void {
            $table->id();

            // Soft links
            $table->unsignedBigInteger('do_id');
            $table->unsignedBigInteger('do_item_id');
            $table->unsignedBigInteger('so_piece_id'); // inv_sales_order_pieces
            $table->unsignedBigInteger('piece_id');    // inv_grn_item_pieces

            // Snapshots (code/weight at selection; store/location/batch stamped
            // from the live piece at confirm)
            $table->string('piece_code', 40);
            $table->decimal('weight', 15, 4)->default(0);
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('batch_id')->nullable();

            // The OUTBOUND ledger row this piece contributed to (set at confirm).
            // GrnItemPiece.stock_transaction_id keeps the inbound GRN link.
            $table->unsignedBigInteger('stock_transaction_id')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index('do_id');
            $table->index('do_item_id');
            $table->index('so_piece_id');
            // Rows are hard-deleted when a draft DO is edited/cancelled/deleted,
            // so a piece can sit on at most one active DO at any time.
            $table->unique('piece_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_delivery_order_pieces');
    }
};
