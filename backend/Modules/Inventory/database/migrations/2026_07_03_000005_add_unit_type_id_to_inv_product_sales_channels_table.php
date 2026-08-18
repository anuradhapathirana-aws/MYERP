<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_product_sales_channels', function (Blueprint $table): void {
            $table->foreignId('unit_type_id')
                ->nullable()
                ->after('sales_channel_id')
                ->constrained('inv_unit_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inv_product_sales_channels', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('unit_type_id');
        });
    }
};
