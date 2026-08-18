<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_product_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('inv_products')
                ->cascadeOnDelete();
            $table->foreignId('attribute_type_id')
                ->nullable()
                ->constrained('inv_attribute_types')
                ->nullOnDelete();
            $table->foreignId('attribute_id')
                ->nullable()
                ->constrained('inv_attributes')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_product_attributes');
    }
};
