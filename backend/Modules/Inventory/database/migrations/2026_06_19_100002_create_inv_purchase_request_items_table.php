<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_purchase_request_items', function (Blueprint $table): void {
            $table->id();

            // Soft link to inv_purchase_requests
            $table->unsignedBigInteger('pr_id');

            // Soft link to inv_products
            $table->unsignedBigInteger('product_id');

            // Soft link to inv_unit_types (UOM)
            $table->unsignedBigInteger('unit_id')->nullable();

            // Requested quantity
            $table->decimal('quantity', 15, 4)->default(0);

            // Estimated price for budgeting purposes (not binding)
            $table->decimal('estimated_unit_price', 15, 4)->nullable();

            // Line-level notes
            $table->string('remarks', 255)->nullable();

            $table->timestamps();

            $table->index('pr_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_purchase_request_items');
    }
};
