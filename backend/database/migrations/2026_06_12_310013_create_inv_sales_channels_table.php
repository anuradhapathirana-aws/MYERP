<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_sales_channels', function (Blueprint $table): void {
            $table->id();
            $table->enum('type', ['Wholesale', 'e-commerce', 'Retail'])->nullable();
            $table->string('sales_channel_name', 100);
            $table->decimal('max_qty', 15, 4)->nullable();
            $table->date('applicable_from')->nullable();
            $table->date('applicable_to')->nullable();
            $table->string('description', 255)->nullable();
            $table->string('status', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_sales_channels');
    }
};
