<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_sales_order_pieces', function (Blueprint $table): void {
            $table->id();

            // Soft links
            $table->unsignedBigInteger('so_id');
            $table->unsignedBigInteger('so_item_id');
            $table->unsignedBigInteger('piece_id'); // inv_grn_item_pieces

            // Snapshots at allocation time
            $table->string('piece_code', 40);
            $table->decimal('weight', 15, 4)->default(0);
            $table->decimal('grn_unit_price', 15, 4)->default(0);

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index('so_id');
            $table->index('so_item_id');
            // Link rows are hard-deleted on release, so this guarantees a piece
            // can only be allocated to one active sales order at a time.
            $table->unique('piece_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_sales_order_pieces');
    }
};
