<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_costing_expense_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('costing_type', 10); // 'fob' | 'cif'
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('costing_type');
            $table->index(['costing_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_costing_expense_types');
    }
};
