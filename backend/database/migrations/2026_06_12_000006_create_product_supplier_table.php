<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_product_supplier', function (Blueprint $table): void {
            $table->foreignId('product_id')
                ->constrained('inv_products')
                ->restrictOnDelete();

            $table->foreignId('supplier_master_id')
                ->constrained('inv_supplier_masters')
                ->restrictOnDelete();

            $table->primary(['product_id', 'supplier_master_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_product_supplier');
    }
};
