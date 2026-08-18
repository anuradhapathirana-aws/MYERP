<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attribute_type_id')
                ->constrained('inv_attribute_types')
                ->restrictOnDelete();
            $table->string('attribute_name', 100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_attributes');
    }
};
