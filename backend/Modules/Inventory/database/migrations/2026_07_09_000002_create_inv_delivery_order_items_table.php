<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Logistics document lines — no pricing columns; prices are read from
        // the linked sales order line at invoicing time.
        Schema::create('inv_delivery_order_items', function (Blueprint $table): void {
            $table->id();

            // Soft links
            $table->unsignedBigInteger('do_id');
            $table->unsignedBigInteger('so_item_id');

            // Snapshots from the SO item
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('attribute_id')->nullable();
            $table->boolean('is_scanned')->default(false);

            // Roll lines: sum of selected piece weights (server-derived);
            // manual lines: user-entered qty <= remaining
            $table->decimal('quantity', 15, 4)->default(0);

            $table->string('remarks', 255)->nullable();

            $table->timestamps();

            $table->index('do_id');
            $table->index('so_item_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_delivery_order_items');
    }
};
