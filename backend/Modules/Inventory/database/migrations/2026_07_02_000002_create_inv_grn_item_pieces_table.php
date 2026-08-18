<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_grn_item_pieces', function (Blueprint $table): void {
            $table->id();

            // Soft links
            $table->unsignedBigInteger('grn_item_id');
            $table->unsignedBigInteger('grn_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();

            $table->unsignedInteger('piece_no');
            $table->string('piece_code', 40)->unique();
            $table->string('status', 20)->default('in_stock');
            $table->timestamp('printed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index('grn_item_id');
            $table->index('grn_id');
            $table->index('product_id');
            $table->index('batch_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_grn_item_pieces');
    }
};
