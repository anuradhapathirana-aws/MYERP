<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_proforma_invoice_items', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('proforma_id');

            // Extendable soft source-line link — 'sales_order_item' today.
            $table->string('source_item_type', 30)->default('sales_order_item');
            $table->unsignedBigInteger('source_item_id')->nullable();
            $table->unsignedBigInteger('so_item_id')->nullable();

            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('price_unit_id')->nullable();
            $table->unsignedBigInteger('attribute_id')->nullable();

            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('discount', 8, 4)->default(0); // %
            $table->decimal('tax', 8, 4)->default(0);      // %
            $table->decimal('line_total', 15, 4)->default(0);

            $table->string('remarks', 255)->nullable();

            $table->timestamps();

            $table->index('proforma_id');
            $table->index(['source_item_type', 'source_item_id']);
            $table->index('so_item_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_proforma_invoice_items');
    }
};
