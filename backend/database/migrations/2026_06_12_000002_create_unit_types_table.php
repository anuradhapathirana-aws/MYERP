<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_unit_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unit_category_id')
                ->constrained('inv_unit_categories')
                ->restrictOnDelete();
            $table->string('name', 100);
            $table->string('symbol', 45);
            $table->string('country', 45)->nullable();
            $table->enum('unit_position', ['prefix', 'suffix'])->default('suffix');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_unit_types');
    }
};
