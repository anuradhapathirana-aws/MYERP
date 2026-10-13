<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-product stock tracking mode: 'roll' (QR-labelled pieces) or 'quantity'
     * (plain qty — finished goods and any ordinary item).
     *
     * Defaults to 'roll' so every existing product — and every caller that does
     * not send the field — keeps today's behaviour exactly: until now every GRN
     * line had to be received as rolls.
     */
    public function up(): void
    {
        if (Schema::hasColumn('inv_products', 'stock_tracking')) {
            return;
        }

        Schema::table('inv_products', function (Blueprint $table): void {
            $table->string('stock_tracking', 20)->default('roll')->after('product_type')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('inv_products', 'stock_tracking')) {
            return;
        }

        Schema::table('inv_products', function (Blueprint $table): void {
            $table->dropIndex(['stock_tracking']);
            $table->dropColumn('stock_tracking');
        });
    }
};
