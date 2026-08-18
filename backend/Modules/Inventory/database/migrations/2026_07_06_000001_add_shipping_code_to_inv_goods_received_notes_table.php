<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_goods_received_notes', function (Blueprint $table): void {
            $table->string('shipping_code', 100)->nullable()->after('reference_no');
        });
    }

    public function down(): void
    {
        Schema::table('inv_goods_received_notes', function (Blueprint $table): void {
            $table->dropColumn('shipping_code');
        });
    }
};
