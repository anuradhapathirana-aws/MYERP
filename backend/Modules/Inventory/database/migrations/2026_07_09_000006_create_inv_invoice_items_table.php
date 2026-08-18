<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_invoice_items', function (Blueprint $table): void {
            $table->id();

            // Soft links
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('so_item_id');
            $table->unsignedBigInteger('do_item_id')->nullable(); // null on direct-SO invoices

            // Snapshots
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('attribute_id')->nullable();

            // Quantities & pricing — copied from DO qty + SO line prices
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('discount', 8, 4)->default(0);   // percentage
            $table->decimal('tax', 8, 4)->default(0);        // percentage
            $table->decimal('line_total', 15, 4)->default(0);

            $table->string('remarks', 255)->nullable();

            $table->timestamps();

            $table->index('invoice_id');
            $table->index('so_item_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_invoice_items');
    }
};
