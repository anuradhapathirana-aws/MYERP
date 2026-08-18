<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_product_location_stores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('inv_products')
                ->cascadeOnDelete();
            $table->foreignId('location_id')
                ->nullable()
                ->constrained('inv_locations')
                ->nullOnDelete();
            $table->foreignId('store_id')
                ->nullable()
                ->constrained('inv_stores')
                ->nullOnDelete();
            $table->decimal('current_stock', 15, 4)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_product_location_stores');
    }
};
