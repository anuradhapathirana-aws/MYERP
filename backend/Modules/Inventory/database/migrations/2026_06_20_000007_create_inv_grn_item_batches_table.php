<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_grn_item_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('grn_item_id');
            $table->unsignedBigInteger('batch_id');
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->timestamps();

            $table->index('grn_item_id');
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_grn_item_batches');
    }
};
