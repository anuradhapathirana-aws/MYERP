<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_product_sales_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('inv_products')
                ->cascadeOnDelete();
            $table->foreignId('sales_channel_id')
                ->constrained('inv_sales_channels')
                ->restrictOnDelete();
            $table->string('uom', 50)->nullable();
            $table->decimal('num_of_units', 15, 4)->nullable();
            $table->decimal('cost_price', 15, 4)->nullable();
            $table->decimal('margin', 10, 4)->nullable();
            $table->enum('margin_type', ['percentage', 'amount'])->default('percentage');
            $table->decimal('selling_price', 15, 4)->nullable();
            $table->decimal('max_price', 15, 4)->nullable();
            $table->decimal('min_price', 15, 4)->nullable();
            $table->decimal('wholesale_price', 15, 4)->nullable();
            $table->decimal('sale_privileges_discount', 5, 2)->nullable();
            $table->decimal('purchasing_privileges_discount', 5, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_product_sales_channels');
    }
};
