<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_costing_expenses', function (Blueprint $table): void {
            $table->id();

            // Soft links — no hard foreign keys across module boundaries
            $table->unsignedBigInteger('costing_id');
            $table->unsignedBigInteger('expense_type_id');

            $table->decimal('amount', 15, 4)->default(0);
            $table->string('note', 255)->nullable();

            $table->timestamps();

            $table->index('costing_id');
            $table->index('expense_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_costing_expenses');
    }
};
