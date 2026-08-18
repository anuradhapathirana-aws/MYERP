<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_sales_order_items', function (Blueprint $table): void {
            $table->decimal('quantity_delivered', 15, 4)->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('inv_sales_order_items', function (Blueprint $table): void {
            $table->dropColumn('quantity_delivered');
        });
    }
};
