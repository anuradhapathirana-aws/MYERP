<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_customer_masters', function (Blueprint $table): void {
            $table->string('customer_tin', 50)->nullable()->after('br_no');
        });
    }

    public function down(): void
    {
        Schema::table('inv_customer_masters', function (Blueprint $table): void {
            $table->dropColumn('customer_tin');
        });
    }
};
