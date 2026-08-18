<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_categories', function (Blueprint $table): void {
            $table->id();
            $table->enum('product_service_type', ['product', 'service'])->default('product');
            $table->foreignId('industry_id')
                ->nullable()
                ->constrained('inv_industries')
                ->nullOnDelete();
            $table->foreignId('company_id')
                ->nullable()
                ->constrained('inv_companies')
                ->nullOnDelete();
            $table->unsignedBigInteger('parent_category_id')->nullable();
            $table->string('category_name', 100);
            $table->string('reference_name', 100)->nullable();
            $table->timestamps();

            $table->foreign('parent_category_id')
                ->references('id')
                ->on('inv_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_categories');
    }
};
