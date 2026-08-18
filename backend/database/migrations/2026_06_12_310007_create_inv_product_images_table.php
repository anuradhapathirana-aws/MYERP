<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_product_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('inv_products')
                ->cascadeOnDelete();
            $table->string('url', 255)->default('default_product.webp');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_product_images');
    }
};
