<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_supplier_return_items', function (Blueprint $table): void {
            $table->id();

            // Soft links
            $table->unsignedBigInteger('return_id');
            $table->unsignedBigInteger('grn_item_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('attribute_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();

            // Quantity in the GRN line's UOM; base_quantity in the stocking UOM
            $table->decimal('quantity', 15, 4);
            $table->decimal('base_quantity', 20, 6)->default(0);

            // Purchase terms copied from the GRN line — a return debits exactly what was charged
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('discount', 15, 4)->default(0);
            $table->decimal('tax', 15, 4)->default(0);
            $table->decimal('line_total', 15, 4)->default(0);

            // Where the goods leave from (header store for quantity lines)
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('location_id');

            $table->string('reason', 30);
            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->index('return_id');
            $table->index('grn_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_supplier_return_items');
    }
};
