<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_location_stores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('location_id')
                ->constrained('inv_locations')
                ->restrictOnDelete();
            $table->foreignId('store_id')
                ->constrained('inv_stores')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(['location_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_location_stores');
    }
};
