<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_attribute_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')
                ->constrained('inv_categories')
                ->restrictOnDelete();
            $table->enum('product_service_type', ['product', 'service'])->default('product');
            $table->string('attribute_type_name', 100);
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_attribute_types');
    }
};
