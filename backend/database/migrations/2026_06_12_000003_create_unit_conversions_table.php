<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_unit_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('from_unit_type_id')
                ->constrained('inv_unit_types')
                ->restrictOnDelete();
            $table->foreignId('to_unit_type_id')
                ->constrained('inv_unit_types')
                ->restrictOnDelete();
            $table->decimal('multiplier', 20, 10);
            $table->timestamps();

            $table->unique(['from_unit_type_id', 'to_unit_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_unit_conversions');
    }
};
