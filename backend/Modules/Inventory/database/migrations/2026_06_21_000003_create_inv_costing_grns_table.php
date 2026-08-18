<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_costing_grns', function (Blueprint $table): void {
            $table->id();

            // Soft links — no hard foreign keys across module boundaries
            $table->unsignedBigInteger('costing_id');
            $table->unsignedBigInteger('grn_id');

            // Denormalized snapshot of GRN total at time of linking
            $table->decimal('grn_total', 15, 4)->default(0);

            $table->timestamps();

            $table->unique(['costing_id', 'grn_id']);
            $table->index('costing_id');
            $table->index('grn_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_costing_grns');
    }
};
