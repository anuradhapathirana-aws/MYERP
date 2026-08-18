<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_goods_received_note_items', function (Blueprint $table): void {
            $table->id();

            // Soft links
            $table->unsignedBigInteger('grn_id');
            $table->unsignedBigInteger('po_item_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('unit_id')->nullable();

            // Quantities
            $table->decimal('quantity_ordered', 15, 4)->default(0);
            $table->decimal('quantity_received', 15, 4)->default(0);

            // Pricing
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('discount', 8, 4)->default(0);
            $table->decimal('tax', 8, 4)->default(0);
            $table->decimal('line_total', 15, 4)->default(0);

            // Batch / serial tracking
            $table->string('batch_no', 100)->nullable();
            $table->date('expiry_date')->nullable();

            $table->timestamps();

            $table->index('grn_id');
            $table->index('po_item_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_goods_received_note_items');
    }
};
