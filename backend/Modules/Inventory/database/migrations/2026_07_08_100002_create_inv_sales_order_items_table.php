<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_sales_order_items', function (Blueprint $table): void {
            $table->id();

            // Soft links
            $table->unsignedBigInteger('so_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('attribute_id')->nullable();

            // true = quantity derived from allocated pieces (QR-scanned line)
            $table->boolean('is_scanned')->default(false);

            // Quantities
            $table->decimal('quantity', 15, 4)->default(0);

            // Pricing
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('discount', 8, 4)->default(0);   // percentage
            $table->decimal('tax', 8, 4)->default(0);        // percentage
            $table->decimal('line_total', 15, 4)->default(0);

            $table->string('remarks', 255)->nullable();

            $table->timestamps();

            $table->index('so_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_sales_order_items');
    }
};
